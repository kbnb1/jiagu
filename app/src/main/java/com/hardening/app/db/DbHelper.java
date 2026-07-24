package com.hardening.app.db;

import android.content.Context;
import android.database.sqlite.SQLiteDatabase;
import android.database.sqlite.SQLiteOpenHelper;

import androidx.annotation.NonNull;

/**
 * SQLite 数据库帮助类。
 *
 * 数据库：hardening.db，版本 1。
 * 仅维护 task_history 一张表，存储加固任务历史用于离线浏览。
 *
 * 升级策略：开发阶段采用 drop + recreate，简单可靠；正式版应改为 ALTER 迁移。
 */
public class DbHelper extends SQLiteOpenHelper {

    public static final String DB_NAME = "hardening.db";
    public static final int DB_VERSION = 1;

    public static final String TABLE_TASK_HISTORY = "task_history";

    // task_history 表列名（与 model.TaskHistory 字段一一对应）
    public static final String COL_ID = "id";
    public static final String COL_TASK_ID = "task_id";
    public static final String COL_FILE_NAME = "file_name";
    public static final String COL_LANGUAGE = "language";
    public static final String COL_STATUS = "status";
    public static final String COL_ORIGINAL_SIZE = "original_size";
    public static final String COL_HARDENED_SIZE = "hardened_size";
    public static final String COL_CREATED_AT = "created_at";
    public static final String COL_DOWNLOAD_URL = "download_url";

    private static volatile DbHelper instance;

    private DbHelper(@NonNull Context context) {
        super(context.getApplicationContext(), DB_NAME, null, DB_VERSION);
    }

    public static DbHelper get(@NonNull Context context) {
        if (instance == null) {
            synchronized (DbHelper.class) {
                if (instance == null) {
                    instance = new DbHelper(context);
                }
            }
        }
        return instance;
    }

    @Override
    public void onCreate(@NonNull SQLiteDatabase db) {
        String sql = "CREATE TABLE " + TABLE_TASK_HISTORY + " ("
                + COL_ID + " INTEGER PRIMARY KEY AUTOINCREMENT, "
                + COL_TASK_ID + " TEXT UNIQUE, "
                + COL_FILE_NAME + " TEXT, "
                + COL_LANGUAGE + " TEXT, "
                + COL_STATUS + " TEXT, "
                + COL_ORIGINAL_SIZE + " INTEGER, "
                + COL_HARDENED_SIZE + " INTEGER, "
                + COL_CREATED_AT + " INTEGER, "
                + COL_DOWNLOAD_URL + " TEXT)";
        db.execSQL(sql);
        // 按任务 ID 查询常用，建索引加速
        db.execSQL("CREATE INDEX idx_task_id ON " + TABLE_TASK_HISTORY
                + "(" + COL_TASK_ID + ")");
        db.execSQL("CREATE INDEX idx_status ON " + TABLE_TASK_HISTORY
                + "(" + COL_STATUS + ")");
    }

    @Override
    public void onUpgrade(@NonNull SQLiteDatabase db, int oldVersion, int newVersion) {
        // 开发阶段：直接删除重建
        db.execSQL("DROP TABLE IF EXISTS " + TABLE_TASK_HISTORY);
        onCreate(db);
    }

    @Override
    public void onDowngrade(@NonNull SQLiteDatabase db, int oldVersion, int newVersion) {
        onUpgrade(db, oldVersion, newVersion);
    }
}
