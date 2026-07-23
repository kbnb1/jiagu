package com.hardening.app.ui;

import android.content.Intent;
import android.os.Bundle;
import android.text.TextUtils;
import android.view.View;
import android.widget.ProgressBar;
import android.widget.Toast;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.textfield.TextInputEditText;
import com.hardening.app.R;
import com.hardening.app.model.ApiResponse;
import com.hardening.app.model.AuthResponse;
import com.hardening.app.model.LoginRequest;
import com.hardening.app.network.ApiClient;
import com.hardening.app.network.TokenManager;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

/**
 * 登录页。
 * 流程：校验输入 → 调用 /api/user/login → 成功则持久化 Token 并跳转主页；失败则提示。
 * 若本地已存在有效 Token，则直接跳过登录进入主页。
 */
public class LoginActivity extends AppCompatActivity {

    private TextInputEditText etUsername;
    private TextInputEditText etPassword;
    private MaterialButton btnLogin;
    private ProgressBar progressBar;

    private Call<ApiResponse<AuthResponse>> loginCall;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // 已登录则直接进主页
        if (TokenManager.get(this).isLoggedIn()) {
            startActivity(new Intent(this, MainActivity.class));
            finish();
            return;
        }

        setContentView(R.layout.activity_login);

        etUsername = findViewById(R.id.etUsername);
        etPassword = findViewById(R.id.etPassword);
        btnLogin = findViewById(R.id.btnLogin);
        progressBar = findViewById(R.id.progressBar);

        btnLogin.setOnClickListener(v -> doLogin());
        findViewById(R.id.tvGoRegister).setOnClickListener(v ->
                startActivity(new Intent(this, RegisterActivity.class)));
    }

    private void doLogin() {
        String username = textOf(etUsername);
        String password = textOf(etPassword);

        if (TextUtils.isEmpty(username) || TextUtils.isEmpty(password)) {
            toast("用户名和密码不能为空");
            return;
        }

        setLoading(true);
        loginCall = ApiClient.get().login(new LoginRequest(username, password));
        loginCall.enqueue(new Callback<ApiResponse<AuthResponse>>() {
            @Override
            public void onResponse(@NonNull Call<ApiResponse<AuthResponse>> call,
                                   @NonNull Response<ApiResponse<AuthResponse>> response) {
                setLoading(false);
                if (!response.isSuccessful() || response.body() == null) {
                    toast("登录失败，HTTP " + response.code());
                    return;
                }
                ApiResponse<AuthResponse> body = response.body();
                if (!body.isSuccess() || body.getData() == null) {
                    toast("登录失败：" + body.getMessage());
                    return;
                }
                onLoginSuccess(body.getData());
            }

            @Override
            public void onFailure(@NonNull Call<ApiResponse<AuthResponse>> call, @NonNull Throwable t) {
                setLoading(false);
                if (call.isCanceled()) return;
                toast("网络异常：" + t.getMessage());
            }
        });
    }

    private void onLoginSuccess(AuthResponse auth) {
        TokenManager tm = TokenManager.get(this);
        tm.saveAuth(auth.getAccessToken(), auth.getRefreshToken(),
                auth.getUser().getId(), auth.getUser().getUsername());

        startActivity(new Intent(this, MainActivity.class));
        finish();
    }

    private void setLoading(boolean loading) {
        progressBar.setVisibility(loading ? View.VISIBLE : View.GONE);
        btnLogin.setEnabled(!loading);
    }

    private String textOf(TextInputEditText et) {
        return et.getText() == null ? "" : et.getText().toString().trim();
    }

    private void toast(String msg) {
        Toast.makeText(this, msg, Toast.LENGTH_SHORT).show();
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        if (loginCall != null && !loginCall.isCanceled()) {
            loginCall.cancel();
        }
    }
}
