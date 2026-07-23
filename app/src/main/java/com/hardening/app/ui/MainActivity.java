package com.hardening.app.ui;

import android.content.Intent;
import android.os.Bundle;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;

import com.google.android.material.button.MaterialButton;
import com.hardening.app.R;
import com.hardening.app.model.ApiResponse;
import com.hardening.app.network.ApiClient;
import com.hardening.app.network.TokenManager;
import com.hardening.app.security.SecurityCheck;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

/**
 * 主页（登录后落地页）。
 *
 * 职责：
 * 1. 启动时执行环境安全检测（Root / 模拟器）；命中风险则弹窗阻止使用并退出。
 * 2. 未登录（Token 被清空）时回退到登录页。
 * 3. 展示当前用户信息，提供退出登录入口。
 *
 * 后续模块（上传 / 轮询 / 下载 / 历史）将以本页为宿主逐步接入。
 */
public class MainActivity extends AppCompatActivity {

    private TextView tvWelcome;
    private TextView tvInfo;
    private MaterialButton btnLogout;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // 防御：未登录直接回登录页
        if (!TokenManager.get(this).isLoggedIn()) {
            goToLogin();
            return;
        }

        // 环境安全检测：Root / 模拟器
        SecurityCheck.Result result = new SecurityCheck(this).check();
        if (!result.safe) {
            showRiskDialog(result.riskText());
            return;
        }

        setContentView(R.layout.activity_main);

        tvWelcome = findViewById(R.id.tvWelcome);
        tvInfo = findViewById(R.id.tvInfo);
        btnLogout = findViewById(R.id.btnLogout);

        TokenManager tm = TokenManager.get(this);
        tvWelcome.setText("欢迎，" + tm.getUsername());
        tvInfo.setText("环境检测：安全\n"
                + "用户 ID：" + tm.getUserId() + "\n"
                + "Token 已加载，有效期 7 天");

        btnLogout.setOnClickListener(v -> doLogout());
    }

    /**
     * 风险环境弹窗：不可取消，点击后直接退出 App。
     */
    private void showRiskDialog(String riskText) {
        new AlertDialog.Builder(this)
                .setTitle("检测到风险环境")
                .setMessage("为保护代码安全，本应用不允许在 Root 或模拟器环境中运行：\n\n"
                        + riskText)
                .setCancelable(false)
                .setPositiveButton("退出", (d, w) -> finishAffinity())
                .show();
    }

    private void doLogout() {
        btnLogout.setEnabled(false);
        // 通知服务端使 refresh_token 失效；无论成功与否都清除本地凭证
        ApiClient.get().logout().enqueue(new Callback<ApiResponse<Void>>() {
            @Override
            public void onResponse(Call<ApiResponse<Void>> call, Response<ApiResponse<Void>> response) {
                clearLocalAndExit();
            }

            @Override
            public void onFailure(Call<ApiResponse<Void>> call, Throwable t) {
                clearLocalAndExit();
            }
        });
    }

    private void clearLocalAndExit() {
        TokenManager.get(this).clear();
        Toast.makeText(this, "已退出登录", Toast.LENGTH_SHORT).show();
        goToLogin();
    }

    private void goToLogin() {
        Intent intent = new Intent(this, LoginActivity.class);
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
        startActivity(intent);
        finish();
    }
}
