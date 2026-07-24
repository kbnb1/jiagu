package com.hardening.app.db;

import androidx.annotation.NonNull;

import com.hardening.app.model.TaskStatus;

/**
 * 任务历史本地模型（对应 task_history 表）。
 *
 * 与 {@link TaskStatus} 的区别：TaskStatus 是服务端返回的完整模型；
 * TaskHistory 仅持久化列表展示所需的最小字段，供离线浏览历史记录。
 *
 * id 为本地自增主键；taskId 对应服务端任务 ID。
 */
public class TaskHistory {

    private long id;
    private long taskId;
    private String taskNo;
    private String language;
    private String status;
    private String sourceFile;
    private String resultFile;
    private long fileSize;
    private String createdAt;

    public TaskHistory() {
    }

    /** 从服务端 TaskStatus 转换为本地历史记录。 */
    @NonNull
    public static TaskHistory fromTaskStatus(@NonNull TaskStatus s) {
        TaskHistory h = new TaskHistory();
        // TaskStatus.taskId 为 String，本地 taskId 为 long，尽量解析数字部分
        h.taskId = parseLong(s.getTaskId());
        h.taskNo = s.getTaskId();
        h.language = s.getLanguage();
        h.status = s.getStatus();
        h.sourceFile = s.getFileName();
        h.resultFile = s.getFileName();
        h.fileSize = s.getOriginalSize();
        h.createdAt = String.valueOf(s.getCreatedAt());
        return h;
    }

    private static long parseLong(String s) {
        if (s == null || s.isEmpty()) return 0L;
        try {
            return Long.parseLong(s);
        } catch (NumberFormatException e) {
            return 0L;
        }
    }

    public long getId() {
        return id;
    }

    public void setId(long id) {
        this.id = id;
    }

    public long getTaskId() {
        return taskId;
    }

    public void setTaskId(long taskId) {
        this.taskId = taskId;
    }

    public String getTaskNo() {
        return taskNo;
    }

    public void setTaskNo(String taskNo) {
        this.taskNo = taskNo;
    }

    public String getLanguage() {
        return language;
    }

    public void setLanguage(String language) {
        this.language = language;
    }

    public String getStatus() {
        return status;
    }

    public void setStatus(String status) {
        this.status = status;
    }

    public String getSourceFile() {
        return sourceFile;
    }

    public void setSourceFile(String sourceFile) {
        this.sourceFile = sourceFile;
    }

    public String getResultFile() {
        return resultFile;
    }

    public void setResultFile(String resultFile) {
        this.resultFile = resultFile;
    }

    public long getFileSize() {
        return fileSize;
    }

    public void setFileSize(long fileSize) {
        this.fileSize = fileSize;
    }

    public String getCreatedAt() {
        return createdAt;
    }

    public void setCreatedAt(String createdAt) {
        this.createdAt = createdAt;
    }
}
