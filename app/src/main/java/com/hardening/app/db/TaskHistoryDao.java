package com.hardening.app.db;

import android.content.ContentValues;
import android.content.Context;
import android.database.Cursor;
import android.database.sqlite.SQLiteDatabase;
import android.text.TextUtils;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;

import com.hardening.app.model.TaskHistory;

import java.util.ArrayList;
import java.util.List;

/**
 * 任务历史 DAO。
 *
 * 直接操作 {@link TaskHistory}（model 包），与 HistoryActivity / HistoryAdapter 共用同一模型。
 * 数据库连接由 {@link DbHelper} 管理（单例缓存），每次操作通过
 * getWritableDatabase / getReadableDatabase 获取，无需手动关闭连接。
 * Cursor 必须在使用后关闭，避免内存泄漏。
 */
public class TaskHistoryDao {

    private final DbHelper dbHelper;

    public TaskHistoryDao(@NonNull Context context) {
        this.dbHelper = DbHelper.get(context);
    }

    // -------------------- 写操作 --------------------

    /**
     * 插入一条历史记录。
     *
     * @return 新记录的 rowId；失败返回 -1。
     */
    public long insert(@NonNull TaskHistory record) {
        SQLiteDatabase db = dbHelper.getWritableDatabase();
        ContentValues cv = toContentValues(record);
        cv.remove(DbHelper.COL_ID);
        return db.insert(DbHelper.TABLE_TASK_HISTORY, null, cv);
    }

    /**
     * 批量插入历史记录（用于服务端分页数据同步本地缓存）。
     * 在单个事务中执行，保证原子性。
     */
    public void insertAll(@Nullable List<TaskHistory> list) {
        if (list == null || list.isEmpty()) {
            return;
        }
        SQLiteDatabase db = dbHelper.getWritableDatabase();
        db.beginTransaction();
        try {
            for (TaskHistory h : list) {
                ContentValues cv = toContentValues(h);
                cv.remove(DbHelper.COL_ID);
                // 使用 INSERT OR REPLACE，按 task_id 去重
                db.insertWithOnConflict(DbHelper.TABLE_TASK_HISTORY, null, cv,
                        SQLiteDatabase.CONFLICT_REPLACE);
            }
            db.setTransactionSuccessful();
        } finally {
            db.endTransaction();
        }
    }

    /**
     * 按服务端任务 ID 删除（用于任务被服务端删除后同步清理本地）。
     *
     * @return 受影响行数。
     */
    public int delete(@Nullable String taskId) {
        if (TextUtils.isEmpty(taskId)) {
            return 0;
        }
        SQLiteDatabase db = dbHelper.getWritableDatabase();
        return db.delete(DbHelper.TABLE_TASK_HISTORY,
                DbHelper.COL_TASK_ID + " = ?",
                new String[]{taskId});
    }

    /**
     * 清空全部历史（用于刷新时重建本地缓存）。
     */
    public void clear() {
        SQLiteDatabase db = dbHelper.getWritableDatabase();
        db.delete(DbHelper.TABLE_TASK_HISTORY, null, null);
    }

    // -------------------- 查询 --------------------

    /**
     * 查询全部历史记录，按创建时间倒序（最新在前）。
     */
    @NonNull
    public List<TaskHistory> queryAll() {
        SQLiteDatabase db = dbHelper.getReadableDatabase();
        Cursor c = db.query(DbHelper.TABLE_TASK_HISTORY, null, null, null,
                null, null, DbHelper.COL_CREATED_AT + " DESC");
        return cursorToList(c);
    }

    /**
     * 分页查询，按创建时间倒序。
     *
     * @param page 页码，从 1 开始（1 表示第一页）
     * @param size 每页条数
     */
    @NonNull
    public List<TaskHistory> queryByPage(int page, int size) {
        if (page < 1) page = 1;
        if (size <= 0) size = 20;
        int offset = (page - 1) * size;
        SQLiteDatabase db = dbHelper.getReadableDatabase();
        // limitClause 格式："offset,limit"
        Cursor c = db.query(DbHelper.TABLE_TASK_HISTORY, null, null, null,
                null, null, DbHelper.COL_CREATED_AT + " DESC",
                offset + "," + size);
        return cursorToList(c);
    }

    /**
     * 按服务端任务 ID 查询单条记录。
     *
     * @return 匹配记录；无匹配返回 null。
     */
    @Nullable
    public TaskHistory queryByTaskId(@Nullable String taskId) {
        if (TextUtils.isEmpty(taskId)) {
            return null;
        }
        SQLiteDatabase db = dbHelper.getReadableDatabase();
        Cursor c = db.query(DbHelper.TABLE_TASK_HISTORY, null,
                DbHelper.COL_TASK_ID + " = ?",
                new String[]{taskId},
                null, null, null, "1");
        return cursorToFirst(c);
    }

    /**
     * 按状态筛选（如 completed / failed），按时间倒序。
     */
    @NonNull
    public List<TaskHistory> queryByStatus(@NonNull String status) {
        SQLiteDatabase db = dbHelper.getReadableDatabase();
        Cursor c = db.query(DbHelper.TABLE_TASK_HISTORY, null,
                DbHelper.COL_STATUS + " = ?",
                new String[]{status},
                null, null, DbHelper.COL_CREATED_AT + " DESC");
        return cursorToList(c);
    }

    /** 统计记录总数。 */
    public int count() {
        SQLiteDatabase db = dbHelper.getReadableDatabase();
        Cursor c = db.rawQuery(
                "SELECT COUNT(*) FROM " + DbHelper.TABLE_TASK_HISTORY, null);
        try {
            return c.moveToFirst() ? c.getInt(0) : 0;
        } finally {
            closeQuietly(c);
        }
    }

    // -------------------- 工具方法 --------------------

    @NonNull
    private ContentValues toContentValues(@NonNull TaskHistory h) {
        ContentValues cv = new ContentValues();
        cv.put(DbHelper.COL_TASK_ID, h.getTaskId());
        cv.put(DbHelper.COL_FILE_NAME, h.getFileName());
        cv.put(DbHelper.COL_LANGUAGE, h.getLanguage());
        cv.put(DbHelper.COL_STATUS, h.getStatus());
        cv.put(DbHelper.COL_ORIGINAL_SIZE, h.getOriginalSize());
        cv.put(DbHelper.COL_HARDENED_SIZE, h.getHardenedSize());
        cv.put(DbHelper.COL_CREATED_AT, h.getCreatedAt());
        cv.put(DbHelper.COL_DOWNLOAD_URL, h.getDownloadUrl());
        return cv;
    }

    @NonNull
    private List<TaskHistory> cursorToList(@NonNull Cursor c) {
        List<TaskHistory> list = new ArrayList<>();
        try {
            while (c.moveToNext()) {
                list.add(readRow(c));
            }
        } finally {
            closeQuietly(c);
        }
        return list;
    }

    @Nullable
    private TaskHistory cursorToFirst(@NonNull Cursor c) {
        try {
            return c.moveToFirst() ? readRow(c) : null;
        } finally {
            closeQuietly(c);
        }
    }

    @NonNull
    private TaskHistory readRow(@NonNull Cursor c) {
        TaskHistory h = new TaskHistory();
        h.setTaskId(getString(c, DbHelper.COL_TASK_ID));
        h.setFileName(getString(c, DbHelper.COL_FILE_NAME));
        h.setLanguage(getString(c, DbHelper.COL_LANGUAGE));
        h.setStatus(getString(c, DbHelper.COL_STATUS));
        h.setOriginalSize(getLong(c, DbHelper.COL_ORIGINAL_SIZE));
        h.setHardenedSize(getLong(c, DbHelper.COL_HARDENED_SIZE));
        h.setCreatedAt(getLong(c, DbHelper.COL_CREATED_AT));
        h.setDownloadUrl(getString(c, DbHelper.COL_DOWNLOAD_URL));
        return h;
    }

    private String getString(@NonNull Cursor c, @NonNull String col) {
        int idx = c.getColumnIndex(col);
        return idx < 0 || c.isNull(idx) ? null : c.getString(idx);
    }

    private long getLong(@NonNull Cursor c, @NonNull String col) {
        int idx = c.getColumnIndex(col);
        return idx < 0 || c.isNull(idx) ? 0L : c.getLong(idx);
    }

    private void closeQuietly(@Nullable Cursor c) {
        if (c != null && !c.isClosed()) {
            c.close();
        }
    }
}
