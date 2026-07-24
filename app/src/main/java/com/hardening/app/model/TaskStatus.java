package com.hardening.app.model;

import com.google.gson.annotations.SerializedName;

import java.util.Locale;

/**
 * 加固任务状态。
 * status 取值：waiting / processing / completed / failed。
 */
public class TaskStatus {

    public static final String WAITING = "waiting";
    public static final String PROCESSING = "processing";
    public static final String COMPLETED = "completed";
    public static final String FAILED = "failed";

    @SerializedName("task_id")
    private String taskId;

    @SerializedName("status")
    private String status;

    @SerializedName("progress")
    private int progress;

    @SerializedName("file_name")
    private String fileName;

    @SerializedName("language")
    private String language;

    @SerializedName("original_size")
    private long originalSize;

    @SerializedName("hardened_size")
    private long hardenedSize;

    @SerializedName("duration")
    private long duration;

    @SerializedName("obfuscation_rate")
    private double obfuscationRate;

    @SerializedName("download_url")
    private String downloadUrl;

    @SerializedName("created_at")
    private long createdAt;

    @SerializedName("error_msg")
    private String errorMsg;

    public String getTaskId() { return taskId; }
    public void setTaskId(String taskId) { this.taskId = taskId; }

    public String getStatus() { return status; }
    public void setStatus(String status) { this.status = status; }

    public int getProgress() { return progress; }
    public void setProgress(int progress) { this.progress = progress; }

    public String getFileName() { return fileName; }
    public void setFileName(String fileName) { this.fileName = fileName; }

    public String getLanguage() { return language; }
    public void setLanguage(String language) { this.language = language; }

    public long getOriginalSize() { return originalSize; }
    public void setOriginalSize(long originalSize) { this.originalSize = originalSize; }

    public long getHardenedSize() { return hardenedSize; }
    public void setHardenedSize(long hardenedSize) { this.hardenedSize = hardenedSize; }

    public long getDuration() { return duration; }
    public void setDuration(long duration) { this.duration = duration; }

    public double getObfuscationRate() { return obfuscationRate; }
    public void setObfuscationRate(double obfuscationRate) { this.obfuscationRate = obfuscationRate; }

    public String getDownloadUrl() { return downloadUrl; }
    public void setDownloadUrl(String downloadUrl) { this.downloadUrl = downloadUrl; }

    public long getCreatedAt() { return createdAt; }
    public void setCreatedAt(long createdAt) { this.createdAt = createdAt; }

    public String getErrorMsg() { return errorMsg; }
    public void setErrorMsg(String errorMsg) { this.errorMsg = errorMsg; }

    public boolean isCompleted() { return COMPLETED.equals(status); }
    public boolean isFailed() { return FAILED.equals(status); }
    public boolean isTerminal() { return isCompleted() || isFailed(); }

    public String formatSize(long bytes) {
        if (bytes < 1024) return bytes + " B";
        if (bytes < 1024 * 1024) return String.format(Locale.getDefault(), "%.1f KB", bytes / 1024.0);
        return String.format(Locale.getDefault(), "%.2f MB", bytes / (1024.0 * 1024.0));
    }

    /**
     * 加固选项（序列化为 JSON 作为 createTask 的 options 字段）。
     * 与兼容接口 uploadTask 的逐项表单字段一一对应。
     */
    public static class TaskOptions {

        @SerializedName("obfuscation_level")
        private int obfuscationLevel;

        @SerializedName("string_encrypt")
        private boolean stringEncrypt;

        @SerializedName("comment_remove")
        private boolean commentRemove;

        @SerializedName("control_flow")
        private boolean controlFlow;

        public TaskOptions() {
        }

        public TaskOptions(int obfuscationLevel, boolean stringEncrypt,
                           boolean commentRemove, boolean controlFlow) {
            this.obfuscationLevel = obfuscationLevel;
            this.stringEncrypt = stringEncrypt;
            this.commentRemove = commentRemove;
            this.controlFlow = controlFlow;
        }

        public int getObfuscationLevel() { return obfuscationLevel; }
        public void setObfuscationLevel(int obfuscationLevel) { this.obfuscationLevel = obfuscationLevel; }

        public boolean isStringEncrypt() { return stringEncrypt; }
        public void setStringEncrypt(boolean stringEncrypt) { this.stringEncrypt = stringEncrypt; }

        public boolean isCommentRemove() { return commentRemove; }
        public void setCommentRemove(boolean commentRemove) { this.commentRemove = commentRemove; }

        public boolean isControlFlow() { return controlFlow; }
        public void setControlFlow(boolean controlFlow) { this.controlFlow = controlFlow; }
    }
}
