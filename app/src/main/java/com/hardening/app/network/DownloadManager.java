package com.hardening.app.network;

import android.content.Context;
import android.net.Uri;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.text.TextUtils;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;

import com.hardening.app.HardeningApp;

import java.io.BufferedInputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.RandomAccessFile;
import java.security.MessageDigest;
import java.util.concurrent.TimeUnit;

import okhttp3.Call;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.Response;
import okhttp3.ResponseBody;

/**
 * 文件下载管理器。
 *
 * 职责：
 * 1. 下载加固产物到指定路径，支持进度回调；
 * 2. 断点续传：基于临时文件已下载字节数发送 Range 请求，206 续传 / 200 重下；
 * 3. 完整性校验：响应头 Content-MD5 / ETag 与本地计算结果对比，不匹配则失败；
 * 4. 原子写入：先写 .tmp 临时文件，校验通过后重命名为最终文件，避免残留半成品；
 * 5. 支持取消：持有 {@link Call} 引用，{@link #cancel()} 中断当前下载。
 *
 * 下载在独立线程同步执行，onProgress / onSuccess / onError 均在主线程回调。
 */
public class DownloadManager {

    /** 临时文件后缀，下载完成后重命名去掉。 */
    private static final String TMP_SUFFIX = ".tmp";

    private static volatile DownloadManager instance;

    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private final OkHttpClient client;

    @Nullable
    private volatile Call currentCall;
    private volatile boolean canceled;

    private DownloadManager() {
        client = new OkHttpClient.Builder()
                .connectTimeout(15, TimeUnit.SECONDS)
                .readTimeout(60, TimeUnit.SECONDS)
                .build();
    }

    public static DownloadManager get() {
        if (instance == null) {
            synchronized (DownloadManager.class) {
                if (instance == null) {
                    instance = new DownloadManager();
                }
            }
        }
        return instance;
    }

    /**
     * 下载加固产物。
     *
     * @param taskId   任务 ID
     * @param savePath 最终保存绝对路径（含文件名）
     * @param callback 进度与结果回调（主线程）
     */
    public void downloadFile(long taskId, @NonNull String savePath,
                             @NonNull DownloadCallback callback) {
        canceled = false;
        currentCall = null;

        Thread worker = new Thread(() -> doDownload(taskId, savePath, callback));
        worker.setName("download-" + taskId);
        worker.start();
    }

    /** 取消当前下载。临时文件保留以便下次续传。 */
    public void cancel() {
        canceled = true;
        if (currentCall != null && !currentCall.isCanceled()) {
            currentCall.cancel();
        }
    }

    public boolean isDownloading() {
        return currentCall != null && !currentCall.isCanceled();
    }

    // -------------------- 兼容接口（供已有 UI 使用） --------------------

    /**
     * 通过系统 DownloadManager 下载文件到公共 Download 目录（兼容接口）。
     *
     * 供 MainActivity / HistoryActivity 调用，返回系统下载 ID（>0 成功）。
     * 若 downloadUrl 为相对路径，自动拼接 {@link HardeningApp#BASE_URL}；
     * 鉴权 Token 通过请求头附加。
     *
     * @param context     上下文
     * @param downloadUrl 下载地址（完整 URL 或相对路径）
     * @param fileName    保存文件名
     * @return 下载任务 ID（>0 成功）；-1 表示失败
     */
    public long download(@NonNull Context context, @NonNull String downloadUrl,
                         @NonNull String fileName) {
        if (TextUtils.isEmpty(downloadUrl)) {
            return -1L;
        }

        // 处理相对路径：自动拼接 BASE_URL
        String url = downloadUrl;
        if (!url.startsWith("http://") && !url.startsWith("https://")) {
            url = HardeningApp.BASE_URL + (url.startsWith("/") ? url.substring(1) : url);
        }

        try {
            android.app.DownloadManager dm = (android.app.DownloadManager)
                    context.getSystemService(Context.DOWNLOAD_SERVICE);
            if (dm == null) {
                return -1L;
            }

            android.app.DownloadManager.Request request =
                    new android.app.DownloadManager.Request(Uri.parse(url));
            request.setTitle(fileName);
            request.setDescription("代码加固产物");
            request.setDestinationInExternalPublicDir(
                    Environment.DIRECTORY_DOWNLOADS, fileName);
            request.setNotificationVisibility(
                    android.app.DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);

            // 鉴权头
            String token = TokenManager.get(context).getAccessToken();
            if (!TextUtils.isEmpty(token)) {
                request.addRequestHeader("Authorization", "Bearer " + token);
            }

            return dm.enqueue(request);
        } catch (Exception e) {
            return -1L;
        }
    }

    private void doDownload(long taskId, String savePath, DownloadCallback callback) {
        File finalFile = new File(savePath);
        File tmpFile = new File(savePath + TMP_SUFFIX);
        // 确保目标目录存在
        File parent = finalFile.getParentFile();
        if (parent != null && !parent.exists() && !parent.mkdirs()) {
            notifyError(callback, "无法创建下载目录");
            return;
        }

        // 已下载字节数（断点）
        long existBytes = tmpFile.exists() ? tmpFile.length() : 0L;

        Request.Builder rb = new Request.Builder()
                .url(HardeningApp.BASE_URL + "api/task/download/" + taskId);

        // 鉴权头
        Context ctx = HardeningApp.get();
        String token = TokenManager.get(ctx).getAccessToken();
        if (token != null) {
            rb.header("Authorization", "Bearer " + token);
        }
        // 断点续传
        if (existBytes > 0) {
            rb.header("Range", "bytes=" + existBytes + "-");
        }

        Request request = rb.build();
        Call call = client.newCall(request);
        currentCall = call;

        try (Response response = call.execute()) {
            if (canceled) return;
            if (!response.isSuccessful()) {
                notifyError(callback, "下载失败，HTTP " + response.code());
                return;
            }
            ResponseBody body = response.body();
            if (body == null) {
                notifyError(callback, "下载失败：响应体为空");
                return;
            }

            int code = response.code();
            boolean resume = (code == 206);
            long total = body.contentLength();
            // 206 时 Content-Length 是剩余字节；Content-Range 形如 bytes 100-999/1000
            String contentRange = response.header("Content-Range");
            if (resume && contentRange != null) {
                total = parseTotalFromRange(contentRange);
            } else if (resume) {
                total += existBytes;
            }

            String expectedMd5 = response.header("Content-MD5");
            if (expectedMd5 == null) {
                String etag = response.header("ETag");
                if (etag != null && etag.length() >= 2 && !etag.startsWith("W/")) {
                    expectedMd5 = etag.replaceAll("\"", "");
                }
            }

            if (!writeStream(body.byteStream(), tmpFile, resume, existBytes, total, callback)) {
                return; // 已被取消或出错，回调已发
            }
            if (canceled) return;

            // 完整性校验
            if (expectedMd5 != null && !verifyMd5(tmpFile, expectedMd5)) {
                // 校验失败：删除临时文件，避免下次误续传损坏数据
                tmpFile.delete();
                notifyError(callback, "文件完整性校验失败（MD5 不匹配）");
                return;
            }

            // 原子重命名
            if (finalFile.exists() && !finalFile.delete()) {
                notifyError(callback, "无法覆盖已有文件");
                return;
            }
            if (!tmpFile.renameTo(finalFile)) {
                notifyError(callback, "保存文件失败");
                return;
            }
            File result = finalFile;
            mainHandler.post(() -> callback.onSuccess(result));

        } catch (IOException e) {
            if (canceled) return;
            // 断点续传场景下中断不删除临时文件，便于下次继续
            notifyError(callback, "下载异常：" + e.getMessage());
        } finally {
            currentCall = null;
        }
    }

    /**
     * 将输入流写入临时文件，实时回调进度。
     *
     * @return true 写入完成；false 被取消或出错（已发回调）。
     */
    private boolean writeStream(@NonNull InputStream is, @NonNull File tmpFile,
                                boolean resume, long startBytes, long total,
                                DownloadCallback callback) {
        RandomAccessFile raf = null;
        try {
            raf = new RandomAccessFile(tmpFile, "rw");
            raf.seek(startBytes);

            byte[] buffer = new byte[8192];
            int read;
            long downloaded = startBytes;
            int lastPercent = startBytes > 0 && total > 0
                    ? (int) (startBytes * 100 / total) : 0;
            while ((read = is.read(buffer)) != -1) {
                if (canceled) return false;
                raf.write(buffer, 0, read);
                downloaded += read;
                if (total > 0) {
                    int percent = (int) (downloaded * 100 / total);
                    if (percent > 100) percent = 100;
                    if (percent != lastPercent) {
                        lastPercent = percent;
                        final int p = percent;
                        mainHandler.post(() -> callback.onProgress(p));
                    }
                }
            }
            raf.getFD().sync();
            return true;
        } catch (IOException e) {
            if (canceled) return false;
            notifyError(callback, "写入文件失败：" + e.getMessage());
            return false;
        } finally {
            if (raf != null) {
                try {
                    raf.close();
                } catch (IOException ignored) {
                }
            }
        }
    }

    /** 解析 Content-Range 头中的总字节数。形如 bytes 100-999/1000 → 1000。 */
    private long parseTotalFromRange(@NonNull String contentRange) {
        int slash = contentRange.lastIndexOf('/');
        if (slash < 0 || slash == contentRange.length() - 1) return -1;
        try {
            return Long.parseLong(contentRange.substring(slash + 1).trim());
        } catch (NumberFormatException e) {
            return -1;
        }
    }

    /** 计算文件 MD5 并与期望值对比。 */
    private boolean verifyMd5(@NonNull File file, @NonNull String expected) {
        try {
            MessageDigest md = MessageDigest.getInstance("MD5");
            try (InputStream fis = new FileInputStream(file);
                 InputStream bis = new BufferedInputStream(fis)) {
                byte[] buf = new byte[8192];
                int n;
                while ((n = bis.read(buf)) != -1) {
                    md.update(buf, 0, n);
                }
            }
            StringBuilder sb = new StringBuilder();
            for (byte b : md.digest()) {
                sb.append(String.format("%02x", b));
            }
            return sb.toString().equalsIgnoreCase(expected);
        } catch (Exception e) {
            return false;
        }
    }

    private void notifyError(@NonNull DownloadCallback callback, @NonNull String msg) {
        mainHandler.post(() -> callback.onError(msg));
    }

    // -------------------- 回调接口 --------------------

    public interface DownloadCallback {
        void onProgress(int percent);

        void onSuccess(File file);

        void onError(String message);
    }
}
