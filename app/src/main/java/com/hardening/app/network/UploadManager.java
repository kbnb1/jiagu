package com.hardening.app.network;

import android.content.Context;
import android.net.Uri;
import android.os.Handler;
import android.os.Looper;
import android.text.TextUtils;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;

import com.google.gson.Gson;
import com.hardening.app.model.ApiResponse;
import com.hardening.app.model.TaskStatus;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.util.Arrays;
import java.util.List;

import okhttp3.MediaType;
import okhttp3.MultipartBody;
import okhttp3.RequestBody;
import okio.Buffer;
import okio.BufferedSink;
import okio.ForwardingSink;
import okio.Okio;
import okio.Sink;
import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

/**
 * 文件上传管理器。
 *
 * 职责：
 * 1. 上传前校验：文件类型白名单 + 大小上限（10MB）；
 * 2. 通过 {@link ProgressRequestBody} 包装请求体，实时回调上传进度；
 * 3. 持有当前 Call 引用，支持 {@link #cancel()} 取消上传；
 * 4. 提供 {@link #newCacheFile(Context, String)} 生成临时缓存路径，统一管理上传缓存。
 *
 * 提供两套上传入口：
 *  - {@link #uploadFile} 规范接口：File + TaskOptions，调用 createTask，回调 onSuccess(TaskStatus)；
 *  - {@link #upload} 兼容接口：Context + Uri + 逐项选项，调用 uploadTask，回调 onSuccess(String taskId)。
 *
 * onProgress / onSuccess / onFailure / onError 均在主线程回调。
 */
public class UploadManager {

    /** 支持上传的源码扩展名。 */
    private static final List<String> ALLOWED_EXT =
            Arrays.asList("java", "php", "js", "py", "cpp", "c", "h", "lua");

    /** 单文件上限 10MB。 */
    public static final long MAX_FILE_SIZE = 10L * 1024 * 1024;

    private static final MediaType MEDIA_FILE = MediaType.parse("application/octet-stream");
    private static final MediaType MEDIA_TEXT = MediaType.parse("text/plain");

    private static volatile UploadManager instance;

    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private final Gson gson = new Gson();

    @Nullable
    private Call<ApiResponse<TaskStatus>> currentCall;

    private UploadManager() {
    }

    public static UploadManager get() {
        if (instance == null) {
            synchronized (UploadManager.class) {
                if (instance == null) {
                    instance = new UploadManager();
                }
            }
        }
        return instance;
    }

    // -------------------- 规范接口 --------------------

    /**
     * 上传文件创建加固任务（规范接口）。
     *
     * @param file     待加固源码文件
     * @param language 语言标识（java/php/js/py/cpp/c/h/lua）
     * @param options  加固选项，序列化为 JSON 作为表单字段
     * @param callback 进度与结果回调（主线程）
     */
    public void uploadFile(@NonNull File file,
                           @NonNull String language,
                           @NonNull TaskStatus.TaskOptions options,
                           @NonNull UploadCallback callback) {
        String validateError = validate(file);
        if (validateError != null) {
            notifyFailure(callback, validateError);
            return;
        }

        cancel();

        ProgressRequestBody progressBody = new ProgressRequestBody(file, MEDIA_FILE, percent ->
                mainHandler.post(() -> callback.onProgress(percent)));

        MultipartBody.Part filePart = MultipartBody.Part.createFormData("file", file.getName(), progressBody);
        RequestBody languagePart = RequestBody.create(language, MEDIA_TEXT);
        RequestBody optionsPart = RequestBody.create(gson.toJson(options), MEDIA_TEXT);

        currentCall = ApiClient.get().createTask(filePart, languagePart, optionsPart);
        currentCall.enqueue(new Callback<ApiResponse<TaskStatus>>() {
            @Override
            public void onResponse(@NonNull Call<ApiResponse<TaskStatus>> call,
                                   @NonNull Response<ApiResponse<TaskStatus>> response) {
                if (call.isCanceled()) return;
                if (!response.isSuccessful() || response.body() == null) {
                    notifyFailure(callback, "上传失败，HTTP " + response.code());
                    return;
                }
                ApiResponse<TaskStatus> body = response.body();
                if (!body.isSuccess() || body.getData() == null) {
                    notifyFailure(callback, "上传失败：" + body.getMessage());
                    return;
                }
                TaskStatus result = body.getData();
                mainHandler.post(() -> callback.onSuccess(result));
            }

            @Override
            public void onFailure(@NonNull Call<ApiResponse<TaskStatus>> call, @NonNull Throwable t) {
                if (call.isCanceled()) return;
                notifyFailure(callback, "网络异常：" + t.getMessage());
            }
        });
    }

    // -------------------- 兼容接口（供已有 UI 使用） --------------------

    /**
     * 上传文件（兼容接口）。
     * 从 Uri 读取文件，逐项传递加固选项，调用 uploadTask 接口。
     *
     * @param context       上下文
     * @param fileUri       文件 Uri（SAF 返回）
     * @param fileName      文件名（含扩展名）
     * @param language      语言标识
     * @param level         混淆等级 1~3
     * @param stringEncrypt 字符串加密开关
     * @param commentRemove 注释移除开关
     * @param controlFlow   控制流平坦化开关
     * @param callback      回调
     */
    public void upload(@NonNull Context context, @NonNull Uri fileUri, @NonNull String fileName,
                       @NonNull String language, int level, boolean stringEncrypt,
                       boolean commentRemove, boolean controlFlow,
                       @NonNull UploadCallback callback) {
        // 1. Uri → 临时文件
        File tmpFile = uriToFile(context, fileUri, fileName);
        if (tmpFile == null) {
            notifyFailure(callback, "无法读取所选文件");
            return;
        }

        // 2. 校验
        String error = validate(tmpFile);
        if (error != null) {
            tmpFile.delete();
            notifyFailure(callback, error);
            return;
        }

        // 3. 取消上一次上传
        cancel();

        // 4. 构造带进度的请求体
        ProgressRequestBody progressBody = new ProgressRequestBody(tmpFile, MEDIA_FILE, percent ->
                mainHandler.post(() -> callback.onProgress(percent)));
        MultipartBody.Part filePart = MultipartBody.Part.createFormData("file", fileName, progressBody);
        RequestBody langPart = RequestBody.create(language, MEDIA_TEXT);
        RequestBody levelPart = RequestBody.create(String.valueOf(level), MEDIA_TEXT);
        RequestBody sePart = RequestBody.create(String.valueOf(stringEncrypt), MEDIA_TEXT);
        RequestBody crPart = RequestBody.create(String.valueOf(commentRemove), MEDIA_TEXT);
        RequestBody cfPart = RequestBody.create(String.valueOf(controlFlow), MEDIA_TEXT);

        // 5. 发起请求
        currentCall = ApiClient.get().uploadTask(filePart, langPart, levelPart,
                sePart, crPart, cfPart);
        currentCall.enqueue(new Callback<ApiResponse<TaskStatus>>() {
            @Override
            public void onResponse(@NonNull Call<ApiResponse<TaskStatus>> call,
                                   @NonNull Response<ApiResponse<TaskStatus>> response) {
                if (call.isCanceled()) return;
                tmpFile.delete();
                if (!response.isSuccessful() || response.body() == null) {
                    notifyFailure(callback, "上传失败，HTTP " + response.code());
                    return;
                }
                ApiResponse<TaskStatus> body = response.body();
                if (!body.isSuccess() || body.getData() == null) {
                    notifyFailure(callback, "上传失败：" + body.getMessage());
                    return;
                }
                String taskId = body.getData().getTaskId();
                mainHandler.post(() -> callback.onSuccess(taskId));
            }

            @Override
            public void onFailure(@NonNull Call<ApiResponse<TaskStatus>> call, @NonNull Throwable t) {
                if (call.isCanceled()) return;
                tmpFile.delete();
                notifyFailure(callback, "网络异常：" + t.getMessage());
            }
        });
    }

    // -------------------- 公共方法 --------------------

    /** 取消当前上传任务（若进行中）。 */
    public void cancel() {
        if (currentCall != null && !currentCall.isCanceled()) {
            currentCall.cancel();
        }
        currentCall = null;
    }

    public boolean isUploading() {
        return currentCall != null && !currentCall.isCanceled();
    }

    /**
     * 校验文件：存在性、可读性、扩展名白名单、大小上限。
     *
     * @return 校验失败原因；null 表示通过。
     */
    @Nullable
    public String validate(@NonNull File file) {
        if (!file.exists() || !file.isFile()) {
            return "文件不存在";
        }
        if (!file.canRead()) {
            return "文件不可读，请检查权限";
        }
        String name = file.getName();
        int dot = name.lastIndexOf('.');
        if (dot <= 0 || dot == name.length() - 1) {
            return "文件名缺少扩展名";
        }
        String ext = name.substring(dot + 1).toLowerCase();
        if (!ALLOWED_EXT.contains(ext)) {
            return "不支持的文件类型：." + ext + "，仅支持 " + ALLOWED_EXT;
        }
        if (file.length() > MAX_FILE_SIZE) {
            return "文件超过 10MB 上限";
        }
        if (file.length() <= 0) {
            return "文件为空";
        }
        return null;
    }

    /** 判断扩展名是否被支持（供 UI 选择文件时预校验）。 */
    public boolean isSupported(@NonNull String fileName) {
        if (TextUtils.isEmpty(fileName)) return false;
        int dot = fileName.lastIndexOf('.');
        if (dot <= 0 || dot == fileName.length() - 1) return false;
        return ALLOWED_EXT.contains(fileName.substring(dot + 1).toLowerCase());
    }

    /**
     * 生成上传缓存文件路径（位于应用专属缓存目录）。
     */
    @NonNull
    public File newCacheFile(@NonNull Context context, @NonNull String suffix) {
        File dir = new File(context.getCacheDir(), "upload");
        if (!dir.exists()) {
            dir.mkdirs();
        }
        return new File(dir, "upload_" + System.currentTimeMillis() + suffix);
    }

    /** 从 Uri 读取内容写入临时文件，返回临时文件；失败返回 null。 */
    @Nullable
    private File uriToFile(@NonNull Context context, @NonNull Uri uri, @NonNull String fileName) {
        File tmp = newCacheFile(context, getExtension(fileName));
        InputStream is = null;
        FileOutputStream fos = null;
        try {
            is = context.getContentResolver().openInputStream(uri);
            if (is == null) return null;
            fos = new FileOutputStream(tmp);
            byte[] buf = new byte[8192];
            int n;
            while ((n = is.read(buf)) != -1) {
                fos.write(buf, 0, n);
            }
            fos.flush();
            return tmp;
        } catch (IOException e) {
            tmp.delete();
            return null;
        } finally {
            closeQuietly(is);
            closeQuietly(fos);
        }
    }

    private String getExtension(@NonNull String fileName) {
        int dot = fileName.lastIndexOf('.');
        return dot >= 0 ? fileName.substring(dot) : ".txt";
    }

    private void closeQuietly(@Nullable java.io.Closeable c) {
        if (c != null) {
            try {
                c.close();
            } catch (IOException ignored) {
            }
        }
    }

    private void notifyFailure(@NonNull UploadCallback callback, @NonNull String msg) {
        mainHandler.post(() -> callback.onFailure(msg));
    }

    // -------------------- 回调接口 --------------------

    /**
     * 上传回调。
     * 兼容两套用法：
     *  - 规范接口：实现 {@link #onSuccess(TaskStatus)} / {@link #onError(String)}（默认实现已桥接）；
     *  - 兼容接口：实现 {@link #onSuccess(String)} / {@link #onFailure(String)}。
     * 实现类必须实现 onSuccess(String) 与 onFailure(String)；onSuccess(TaskStatus) / onError 为可选。
     */
    public interface UploadCallback {
        void onProgress(int percent);

        /** 兼容：成功，仅携带 taskId。 */
        void onSuccess(String taskId);

        /** 兼容：失败。 */
        void onFailure(String error);

        /** 规范：成功，携带完整 TaskStatus。默认委托给 onSuccess(String)。 */
        default void onSuccess(TaskStatus status) {
            onSuccess(status != null ? status.getTaskId() : null);
        }

        /** 规范：错误。默认委托给 onFailure(String)。 */
        default void onError(String message) {
            onFailure(message);
        }
    }

    // -------------------- 进度请求体 --------------------

    /**
     * 包装文件请求体，在写入网络时实时统计已写字节并回调进度。
     */
    private static final class ProgressRequestBody extends RequestBody {

        private final File file;
        private final MediaType contentType;
        private final ProgressListener listener;

        ProgressRequestBody(File file, MediaType contentType, ProgressListener listener) {
            this.file = file;
            this.contentType = contentType;
            this.listener = listener;
        }

        @Override
        public MediaType contentType() {
            return contentType;
        }

        @Override
        public long contentLength() {
            return file.length();
        }

        @Override
        public void writeTo(@NonNull BufferedSink sink) throws IOException {
            SourceCountingSink countingSink = new SourceCountingSink(sink, file.length(), listener);
            BufferedSink bufferedSink = Okio.buffer(countingSink);
            RequestBody sourceBody = RequestBody.create(file, contentType);
            sourceBody.writeTo(bufferedSink);
            bufferedSink.flush();
        }
    }

    private interface ProgressListener {
        void onProgress(int percent);
    }

    /** 统计写入字节数的 Sink 包装器。 */
    private static final class SourceCountingSink extends ForwardingSink {

        private final long total;
        private final ProgressListener listener;
        private long bytesWritten;
        private int lastPercent = -1;

        SourceCountingSink(Sink delegate, long total, ProgressListener listener) {
            super(delegate);
            this.total = total;
            this.listener = listener;
        }

        @Override
        public void write(@NonNull Buffer source, long byteCount) throws IOException {
            super.write(source, byteCount);
            bytesWritten += byteCount;
            if (total <= 0) return;
            int percent = (int) (bytesWritten * 100 / total);
            if (percent > 100) percent = 100;
            if (percent != lastPercent) {
                lastPercent = percent;
                listener.onProgress(percent);
            }
        }
    }
}
