package com.hardening.app.ui;

import android.content.Intent;
import android.os.Bundle;
import android.text.TextUtils;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;
import androidx.appcompat.widget.Toolbar;

import com.google.android.material.bottomnavigation.BottomNavigationView;
import com.google.android.material.button.MaterialButton;
import com.google.android.material.textfield.TextInputEditText;
import com.google.android.material.textfield.TextInputLayout;
import com.hardening.app.R;
import com.hardening.app.model.ApiResponse;
import com.hardening.app.model.ChangePasswordRequest;
import com.hardening.app.model.UserAccount;
import com.hardening.app.network.ApiClient;
import com.hardening.app.network.TokenManager;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

/**
 * 个人中心。
 * - 用户信息（头像、用户名、注册时间）
 * - 账户信息（当前套餐、已用额度）
 * - 功能菜单：修改密码 / 我的订单 / 关于我们 / 退出登录
 * - 修改密码弹窗
 */
public class ProfileActivity extends AppCompatActivity {

    private TextView tvUsername;
    private TextView tvRegisterTime;
    private TextView tvPlanName;
    private TextView tvQuota;
    private ProgressBar progressQuota;
    private MaterialButton btnLogout;
    private BottomNavigationView bottomNav;

    private Call<ApiResponse<UserAccount>> accountCall;
    private Call<ApiResponse<Void>> pwdCall;
    private Call<ApiResponse<Void>> logoutCall;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        if (!TokenManager.get(this).isLoggedIn()) {
            startActivity(new Intent(this, LoginActivity.class)
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK));
            finish();
            return;
        }

        setContentView(R.layout.activity_profile);

        Toolbar toolbar = findViewById(R.id.toolbar);
        toolbar.setNavigationOnClickListener(v -> finish());

        tvUsername = findViewById(R.id.tvUsername);
        tvRegisterTime = findViewById(R.id.tvRegisterTime);
        tvPlanName = findViewById(R.id.tvPlanName);
        tvQuota = findViewById(R.id.tvQuota);
        progressQuota = findViewById(R.id.progressQuota);
        btnLogout = findViewById(R.id.btnLogout);
        bottomNav = findViewById(R.id.bottomNav);

        findViewById(R.id.menuChangePassword).setOnClickListener(v -> showChangePasswordDialog());
        findViewById(R.id.menuOrders).setOnClickListener(v -> toast("订单功能开发中"));
        findViewById(R.id.menuAbout).setOnClickListener(v ->
                startActivity(new Intent(this, AboutActivity.class)));
        btnLogout.setOnClickListener(v -> confirmLogout());

        setupBottomNav();
        loadAccountInfo();
    }

    private void setupBottomNav() {
        bottomNav.setSelectedItemId(R.id.nav_profile);
        bottomNav.setOnItemSelectedListener(item -> {
            int id = item.getItemId();
            if (id == R.id.nav_profile) {
                return true;
            } else if (id == R.id.nav_home) {
                startActivity(new Intent(this, MainActivity.class));
                return false;
            } else if (id == R.id.nav_history) {
                startActivity(new Intent(this, HistoryActivity.class));
                return false;
            } else if (id == R.id.nav_plan) {
                startActivity(new Intent(this, PlanActivity.class));
                return false;
            }
            return false;
        });
    }

    private void loadAccountInfo() {
        TokenManager tm = TokenManager.get(this);
        tvUsername.setText(tm.getUsername());

        accountCall = ApiClient.get().getUserAccount();
        accountCall.enqueue(new Callback<ApiResponse<UserAccount>>() {
            @Override
            public void onResponse(@NonNull Call<ApiResponse<UserAccount>> call,
                                   @NonNull Response<ApiResponse<UserAccount>> response) {
                if (response.body() != null && response.body().isSuccess()
                        && response.body().getData() != null) {
                    applyAccount(response.body().getData());
                }
            }

            @Override
            public void onFailure(@NonNull Call<ApiResponse<UserAccount>> call, Throwable t) {
                // 使用默认值
            }
        });
    }

    private void applyAccount(UserAccount acc) {
        if (!TextUtils.isEmpty(acc.getUsername())) {
            tvUsername.setText(acc.getUsername());
        }
        tvRegisterTime.setText("注册时间：" + safe(acc.getCreatedAt(), "-"));
        tvPlanName.setText(safe(acc.getPlanName(), "免费版"));
        int total = acc.getTotalQuota() > 0 ? acc.getTotalQuota() : 5;
        int used = Math.max(0, acc.getUsedQuota());
        tvQuota.setText(used + " / " + (total == Integer.MAX_VALUE ? "∞" : total));
        progressQuota.setProgress(acc.quotaPercent());
    }

    private void showChangePasswordDialog() {
        TextInputLayout oldLayout = new TextInputLayout(this);
        oldLayout.setHint("原密码");
        TextInputEditText etOld = new TextInputEditText(this);
        etOld.setInputType(android.text.InputType.TYPE_CLASS_TEXT
                | android.text.InputType.TYPE_TEXT_VARIATION_PASSWORD);
        oldLayout.addView(etOld);

        TextInputLayout newLayout = new TextInputLayout(this);
        newLayout.setHint("新密码");
        TextInputEditText etNew = new TextInputEditText(this);
        etNew.setInputType(android.text.InputType.TYPE_CLASS_TEXT
                | android.text.InputType.TYPE_TEXT_VARIATION_PASSWORD);
        newLayout.addView(etNew);

        TextInputLayout confirmLayout = new TextInputLayout(this);
        confirmLayout.setHint("确认新密码");
        TextInputEditText etConfirm = new TextInputEditText(this);
        etConfirm.setInputType(android.text.InputType.TYPE_CLASS_TEXT
                | android.text.InputType.TYPE_TEXT_VARIATION_PASSWORD);
        confirmLayout.addView(etConfirm);

        android.widget.LinearLayout container = new android.widget.LinearLayout(this);
        container.setOrientation(android.widget.LinearLayout.VERTICAL);
        int pad = (int) (20 * getResources().getDisplayMetrics().density);
        container.setPadding(pad, pad, pad, pad);
        container.addView(oldLayout);
        container.addView(newLayout);
        container.addView(confirmLayout);

        new AlertDialog.Builder(this)
                .setTitle("修改密码")
                .setView(container)
                .setPositiveButton("确认", (d, w) -> {
                    String oldPwd = textOf(etOld);
                    String newPwd = textOf(etNew);
                    String confirm = textOf(etConfirm);
                    if (TextUtils.isEmpty(oldPwd) || TextUtils.isEmpty(newPwd)) {
                        toast("密码不能为空");
                        return;
                    }
                    if (!newPwd.equals(confirm)) {
                        toast("两次输入的新密码不一致");
                        return;
                    }
                    if (newPwd.length() < 6) {
                        toast("新密码至少 6 位");
                        return;
                    }
                    changePassword(oldPwd, newPwd);
                })
                .setNegativeButton("取消", null)
                .show();
    }

    private void changePassword(String oldPwd, String newPwd) {
        pwdCall = ApiClient.get().changePassword(new ChangePasswordRequest(oldPwd, newPwd));
        pwdCall.enqueue(new Callback<ApiResponse<Void>>() {
            @Override
            public void onResponse(@NonNull Call<ApiResponse<Void>> call,
                                   @NonNull Response<ApiResponse<Void>> response) {
                if (response.body() != null && response.body().isSuccess()) {
                    toast("密码修改成功，请重新登录");
                    doLogout();
                } else {
                    String msg = response.body() == null ? "修改失败"
                            : response.body().getMessage();
                    toast("修改失败：" + msg);
                }
            }

            @Override
            public void onFailure(@NonNull Call<ApiResponse<Void>> call, Throwable t) {
                toast("网络异常：" + t.getMessage());
            }
        });
    }

    private void confirmLogout() {
        new AlertDialog.Builder(this)
                .setTitle("退出登录")
                .setMessage("确定要退出登录吗？")
                .setPositiveButton("退出", (d, w) -> doLogout())
                .setNegativeButton("取消", null)
                .show();
    }

    private void doLogout() {
        logoutCall = ApiClient.get().logout();
        logoutCall.enqueue(new Callback<ApiResponse<Void>>() {
            @Override
            public void onResponse(@NonNull Call<ApiResponse<Void>> call,
                                   @NonNull Response<ApiResponse<Void>> response) {
                clearAndExit();
            }

            @Override
            public void onFailure(@NonNull Call<ApiResponse<Void>> call, Throwable t) {
                clearAndExit();
            }
        });
    }

    private void clearAndExit() {
        TokenManager.get(this).clear();
        toast("已退出登录");
        Intent intent = new Intent(this, LoginActivity.class);
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
        startActivity(intent);
        finish();
    }

    private String textOf(TextInputEditText et) {
        return et.getText() == null ? "" : et.getText().toString().trim();
    }

    private String safe(String s, String def) {
        return TextUtils.isEmpty(s) ? def : s;
    }

    private void toast(String msg) {
        Toast.makeText(this, msg, Toast.LENGTH_SHORT).show();
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        if (accountCall != null && !accountCall.isCanceled()) accountCall.cancel();
        if (pwdCall != null && !pwdCall.isCanceled()) pwdCall.cancel();
        if (logoutCall != null && !logoutCall.isCanceled()) logoutCall.cancel();
    }
}
