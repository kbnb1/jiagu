package com.hardening.app.ui;

import android.content.Intent;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;

import androidx.appcompat.app.AppCompatActivity;

import com.hardening.app.network.TokenManager;

/**
 * 启动页（Splash）。
 *
 * 作为应用唯一 LAUNCHER 入口，短暂展示后判断登录状态路由：
 *   已登录 → MainActivity
 *   未登录 → LoginActivity
 *
 * 使用 postDelayed 保证 Splash 至少展示一段时间，避免一闪而过。
 */
public class SplashActivity extends AppCompatActivity {

    private static final long SPLASH_DELAY_MS = 800L;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        // 延迟后路由，期间可加载初始化资源
        new Handler(Looper.getMainLooper()).postDelayed(this::route, SPLASH_DELAY_MS);
    }

    private void route() {
        Intent intent;
        if (TokenManager.get(this).isLoggedIn()) {
            intent = new Intent(this, MainActivity.class);
        } else {
            intent = new Intent(this, LoginActivity.class);
        }
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
        startActivity(intent);
        finish();
    }

    @Override
    public void onBackPressed() {
        // 禁止在 Splash 期间返回，避免出现空白栈
    }
}
