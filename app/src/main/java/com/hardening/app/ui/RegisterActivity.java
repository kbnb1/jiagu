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
import com.hardening.app.model.RegisterRequest;
import com.hardening.app.network.ApiClient;
import com.hardening.app.network.TokenManager;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

/**
 * 注册页。
 * 流程：校验输入 + 两次密码一致 → 调用 /api/user/register → 成功则视为登录，持久化 Token 并跳主页。
 */
public class RegisterActivity extends AppCompatActivity {

    private TextInputEditText etUsername;
    private TextInputEditText etPassword;
    private TextInputEditText etPasswordConfirm;
    private MaterialButton btnRegister;
    private ProgressBar progressBar;

    private Call<ApiResponse<AuthResponse>> registerCall;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_register);

        etUsername = findViewById(R.id.etUsername);
        etPassword = findViewById(R.id.etPassword);
        etPasswordConfirm = findViewById(R.id.etPasswordConfirm);
        btnRegister = findViewById(R.id.btnRegister);
        progressBar = findViewById(R.id.progressBar);

        btnRegister.setOnClickListener(v -> doRegister());
        findViewById(R.id.tvBackLogin).setOnClickListener(v -> finish());
    }

    private void doRegister() {
        String username = textOf(etUsername);
        String password = textOf(etPassword);
        String confirm = textOf(etPasswordConfirm);

        if (TextUtils.isEmpty(username) || TextUtils.isEmpty(password)) {
            toast("用户名和密码不能为空");
            return;
        }
        if (password.length() < 6) {
            toast("密码至少 6 位");
            return;
        }
        if (!password.equals(confirm)) {
            toast("两次输入的密码不一致");
            return;
        }

        setLoading(true);
        registerCall = ApiClient.get().register(new RegisterRequest(username, password));
        registerCall.enqueue(new Callback<ApiResponse<AuthResponse>>() {
            @Override
            public void onResponse(@NonNull Call<ApiResponse<AuthResponse>> call,
                                   @NonNull Response<ApiResponse<AuthResponse>> response) {
                setLoading(false);
                if (!response.isSuccessful() || response.body() == null) {
                    toast("注册失败，HTTP " + response.code());
                    return;
                }
                ApiResponse<AuthResponse> body = response.body();
                if (!body.isSuccess() || body.getData() == null) {
                    toast("注册失败：" + body.getMessage());
                    return;
                }
                onRegisterSuccess(body.getData());
            }

            @Override
            public void onFailure(@NonNull Call<ApiResponse<AuthResponse>> call, @NonNull Throwable t) {
                setLoading(false);
                if (call.isCanceled()) return;
                toast("网络异常：" + t.getMessage());
            }
        });
    }

    private void onRegisterSuccess(AuthResponse auth) {
        TokenManager tm = TokenManager.get(this);
        tm.saveAuth(auth.getAccessToken(), auth.getRefreshToken(),
                auth.getUser().getId(), auth.getUser().getUsername());

        toast("注册成功");
        Intent intent = new Intent(this, MainActivity.class);
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
        startActivity(intent);
    }

    private void setLoading(boolean loading) {
        progressBar.setVisibility(loading ? View.VISIBLE : View.GONE);
        btnRegister.setEnabled(!loading);
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
        if (registerCall != null && !registerCall.isCanceled()) {
            registerCall.cancel();
        }
    }
}
