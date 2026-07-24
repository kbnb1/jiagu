package com.hardening.app.model;

import com.google.gson.annotations.SerializedName;

/**
 * 上传结果模型。
 * /api/task/create 成功后返回的最小信息：用于后续轮询状态与下载产物。
 */
public class UploadResult {

    @SerializedName("task_id")
    private long taskId;

    @SerializedName("task_no")
    private String taskNo;

    @SerializedName("file_name")
    private String fileName;

    @SerializedName("file_size")
    private long fileSize;

    public long getTaskId() {
        return taskId;
    }

    public String getTaskNo() {
        return taskNo;
    }

    public String getFileName() {
        return fileName;
    }

    public long getFileSize() {
        return fileSize;
    }
}
