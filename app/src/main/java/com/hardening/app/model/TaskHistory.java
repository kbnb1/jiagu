package com.hardening.app.model;

import com.google.gson.annotations.SerializedName;

/**
 * 历史任务记录（本地 DB 与服务端共用同一结构）。
 */
public class TaskHistory {

    @SerializedName("task_id")
    private String taskId;

    @SerializedName("file_name")
    private String fileName;

    @SerializedName("language")
    private String language;

    @SerializedName("status")
    private String status;

    @SerializedName("original_size")
    private long originalSize;

    @SerializedName("hardened_size")
    private long hardenedSize;

    @SerializedName("created_at")
    private long createdAt;

    @SerializedName("download_url")
    private String downloadUrl;

    public TaskHistory() {
    }

    public TaskHistory(String taskId, String fileName, String language,
                       String status, long createdAt) {
        this.taskId = taskId;
        this.fileName = fileName;
        this.language = language;
        this.status = status;
        this.createdAt = createdAt;
    }

    public String getTaskId() { return taskId; }
    public void setTaskId(String taskId) { this.taskId = taskId; }

    public String getFileName() { return fileName; }
    public void setFileName(String fileName) { this.fileName = fileName; }

    public String getLanguage() { return language; }
    public void setLanguage(String language) { this.language = language; }

    public String getStatus() { return status; }
    public void setStatus(String status) { this.status = status; }

    public long getOriginalSize() { return originalSize; }
    public void setOriginalSize(long originalSize) { this.originalSize = originalSize; }

    public long getHardenedSize() { return hardenedSize; }
    public void setHardenedSize(long hardenedSize) { this.hardenedSize = hardenedSize; }

    public long getCreatedAt() { return createdAt; }
    public void setCreatedAt(long createdAt) { this.createdAt = createdAt; }

    public String getDownloadUrl() { return downloadUrl; }
    public void setDownloadUrl(String downloadUrl) { this.downloadUrl = downloadUrl; }
}
