package com.hardening.app;

import android.app.Application;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.os.Build;

import com.hardening.app.network.ApiClient;

/**
 * 应用入口。
 * 在这里初始化全局网络配置（Retrofit / OkHttp），避免在多个 Activity 中重复构建。
 */
public class HardeningApp extends Application {

    /** 后端 API 基地址，全部走 HTTPS。 */
    public static final String BASE_URL = "https://api.hardening.example.com/";

    private static HardeningApp instance;

    @Override
    public void onCreate() {
        super.onCreate();
        instance = this;
        // 提前初始化 Retrofit 单例，让首次请求更快
        ApiClient.init(this, BASE_URL);
        createNotificationChannel();
    }

    public static HardeningApp get() {
        return instance;
    }

    /**
     * 预留通知渠道：加固任务完成 / 失败时可通过通知提醒用户。
     */
    private void createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel channel = new NotificationChannel(
                    "task_status",
                    "加固任务状态",
                    NotificationManager.IMPORTANCE_LOW);
            channel.setDescription("加固任务完成或失败时通知");
            NotificationManager nm = getSystemService(NotificationManager.class);
            if (nm != null) {
                nm.createNotificationChannel(channel);
            }
        }
    }
}
