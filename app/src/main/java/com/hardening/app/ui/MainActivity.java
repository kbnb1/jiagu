package com.hardening.app.ui;

import android.app.Activity;
import android.content.Intent;
import android.database.Cursor;
import android.net.Uri;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.provider.OpenableColumns;
import android.text.TextUtils;
import android.view.View;
import android.widget.ArrayAdapter;
import android.widget.ImageView;
import android.widget.ProgressBar;
import android.widget.RadioGroup;
import android.widget.Spinner;
import android.widget.TextView;
import android.widget.Toast;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;

import com.google.android.material.bottomnavigation.BottomNavigationView;
import com.google.android.material.button.MaterialButton;
import com.google.android.material.card.MaterialCardView;
import com.google.android.material.switchmaterial.SwitchMaterial;
import com.hardening.app.R;
import com.hardening.app.model.ApiResponse;
import com.hardening.app.model.TaskStatus;
import com.hardening.app.network.ApiClient;
import com.hardening.app.network.DownloadManager;
import com.hardening.app.network.TokenManager;
import com.hardening.app.network.UploadManager;
import com.hardening.app.security.SecurityCheck;

import java.util.Arrays;
import java.util.List;
import java.util.Locale;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

/**
 * 主页面（加固 Tab）。
 *
 * 职责：
 * 1. 展示当前用户信息与套餐。
 * 2. 选择代码文件（SAF）→ 配置加固选项 → 上传（UploadManager）→ 轮询状态 → 下载结果。
 * 3. 底部 4 Tab 导航：加固 / 历史 / 套餐 / 我的。
 * 4. 环境安全检测：Root / 模拟器阻断。
 */
public class MainActivity extends AppCompatActivity {

    private static final long POLL_INTERVAL_MS = 2000L;

    private static final List<String> LANGUAGES = Arrays.asList(
            "Java", "PHP", "JavaScript", "Python", "C++");

    // 用户信息区
    private ImageView ivAvatar;
    private TextView tvUsername;
    private TextView tvPlan;
    private TextView tvUpgrade;

    // 文件选择区
    private MaterialButton btnSelectFile;
    private TextView tvFileName;

    // 加固选项区
    private Spinner spinnerLanguage;
    private RadioGroup radioGroupLevel;
    private SwitchMaterial switchStringEncrypt;
    private SwitchMaterial switchCommentRemove;
    private SwitchMaterial switchControlFlow;
    private MaterialButton btnStartHardening;

    // 进度区
    private MaterialCardView cardProgress;
    private TextView tvStatusText;
    private ProgressBar progressUpload;
    private TextView tvUploadPercent;
    private ProgressBar progressHardening;
    private TextView tvHardeningStatus;

    // 结果区
    private MaterialCardView cardResult;
    private TextView tvStats;
    private MaterialButton btnDownload;

    // 底部导航
    private BottomNavigationView bottomNav;

    // 状态
    private Uri selectedFileUri;
    private String selectedFileName;
    private String currentTaskId;
    private boolean isProcessing = false;
    private TaskStatus lastStatus;

    private final Handler pollHandler = new Handler(Looper.getMainLooper());
    private Call<ApiResponse<TaskStatus>> statusCall;
    private ActivityResultLauncher<Intent> filePickerLauncher;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        if (!TokenManager.get(this).isLoggedIn()) {
            goToLogin();
            return;
        }

        SecurityCheck.Result risk = new SecurityCheck(this).check();
        if (!risk.safe) {
            showRiskDialog(risk.riskText());
            return;
        }

        setContentView(R.layout.activity_main);

        initViews();
        setupSpinner();
        setupBottomNav();
        registerFilePicker();
        loadUserInfo();
        bindEvents();
    }

    private void initViews() {
        ivAvatar = findViewById(R.id.ivAvatar);
        tvUsername = findViewById(R.id.tvUsername);
        tvPlan = findViewById(R.id.tvPlan);
        tvUpgrade = findViewById(R.id.tvUpgrade);

        btnSelectFile = findViewById(R.id.btnSelectFile);
        tvFileName = findViewById(R.id.tvFileName);

        spinnerLanguage = findViewById(R.id.spinnerLanguage);
        radioGroupLevel = findViewById(R.id.radioGroupLevel);
        switchStringEncrypt = findViewById(R.id.switchStringEncrypt);
        switchCommentRemove = findViewById(R.id.switchCommentRemove);
        switchControlFlow = findViewById(R.id.switchControlFlow);
        btnStartHardening = findViewById(R.id.btnStartHardening);

        cardProgress = findViewById(R.id.cardProgress);
        tvStatusText = findViewById(R.id.tvStatusText);
        progressUpload = findViewById(R.id.progressUpload);
        tvUploadPercent = findViewById(R.id.tvUploadPercent);
        progressHardening = findViewById(R.id.progressHardening);
        tvHardeningStatus = findViewById(R.id.tvHardeningStatus);

        cardResult = findViewById(R.id.cardResult);
        tvStats = findViewById(R.id.tvStats);
        btnDownload = findViewById(R.id.btnDownload);

        bottomNav = findViewById(R.id.bottomNav);
    }

    private void setupSpinner() {
        ArrayAdapter<String> adapter = new ArrayAdapter<>(this,
                android.R.layout.simple_spinner_item, LANGUAGES);
        adapter.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
        spinnerLanguage.setAdapter(adapter);
    }

    private void setupBottomNav() {
        bottomNav.setSelectedItemId(R.id.nav_home);
        bottomNav.setOnItemSelectedListener(item -> {
            int id = item.getItemId();
            if (id == R.id.nav_home) {
                return true;
            } else if (id == R.id.nav_history) {
                startActivity(new Intent(this, HistoryActivity.class));
                return false;
            } else if (id == R.id.nav_plan) {
                startActivity(new Intent(this, PlanActivity.class));
                return false;
            } else if (id == R.id.nav_profile) {
                startActivity(new Intent(this, ProfileActivity.class));
                return false;
            }
            return false;
        });
    }

    private void registerFilePicker() {
        filePickerLauncher = registerForActivityResult(
                new ActivityResultContracts.StartActivityForResult(), result -> {
                    if (result.getResultCode() == Activity.RESULT_OK && result.getData() != null) {
                        Uri uri = result.getData().getData();
                        if (uri != null) {
                            try {
                                getContentResolver().takePersistableUriPermission(
                                        uri, Intent.FLAG_GRANT_READ_URI_PERMISSION);
                            } catch (SecurityException ignored) {
                            }
                            selectedFileUri = uri;
                            selectedFileName = queryFileName(uri);
                            tvFileName.setText(TextUtils.isEmpty(selectedFileName)
                                    ? "已选择文件" : selectedFileName);
                        }
                    }
                });
    }

    private String queryFileName(Uri uri) {
        String name = null;
        try (Cursor c = getContentResolver().query(uri, null, null, null, null)) {
            if (c != null && c.moveToFirst()) {
                int idx = c.getColumnIndex(OpenableColumns.DISPLAY_NAME);
                if (idx >= 0) {
                    name = c.getString(idx);
                }
            }
        } catch (Exception ignored) {
        }
        return name;
    }

    private void loadUserInfo() {
        TokenManager tm = TokenManager.get(this);
        tvUsername.setText(tm.getUsername());
        tvPlan.setText("当前套餐：免费版");

        ApiClient.get().getUserAccount().enqueue(new Callback<ApiResponse<com.hardening.app.model.UserAccount>>() {
            @Override
            public void onResponse(Call<ApiResponse<com.hardening.app.model.UserAccount>> call,
                                   Response<ApiResponse<com.hardening.app.model.UserAccount>> response) {
                if (response.body() != null && response.body().isSuccess()
                        && response.body().getData() != null) {
                    com.hardening.app.model.UserAccount acc = response.body().getData();
                    if (!TextUtils.isEmpty(acc.getUsername())) {
                        tvUsername.setText(acc.getUsername());
                    }
                    tvPlan.setText("当前套餐：" + (TextUtils.isEmpty(acc.getPlanName())
                            ? "免费版" : acc.getPlanName()));
                }
            }

            @Override
            public void onFailure(Call<ApiResponse<com.hardening.app.model.UserAccount>> call, Throwable t) {
                // 静默失败，使用本地默认值
            }
        });

        tvUpgrade.setOnClickListener(v -> startActivity(new Intent(this, PlanActivity.class)));
    }

    private void bindEvents() {
        btnSelectFile.setOnClickListener(v -> openFilePicker());
        btnStartHardening.setOnClickListener(v -> startHardening());
        btnDownload.setOnClickListener(v -> downloadResult());
    }

    private void openFilePicker() {
        Intent intent = new Intent(Intent.ACTION_OPEN_DOCUMENT);
        intent.addCategory(Intent.CATEGORY_OPENABLE);
        intent.setType("*/*");
        filePickerLauncher.launch(intent);
    }

    private void startHardening() {
        if (isProcessing) {
            toast("正在处理中，请稍候");
            return;
        }
        if (selectedFileUri == null) {
            toast("请先选择代码文件");
            return;
        }

        String language = (String) spinnerLanguage.getSelectedItem();
        int level = selectedLevel();
        boolean stringEncrypt = switchStringEncrypt.isChecked();
        boolean commentRemove = switchCommentRemove.isChecked();
        boolean controlFlow = switchControlFlow.isChecked();

        resetProgressUI();
        cardProgress.setVisibility(View.VISIBLE);
        cardResult.setVisibility(View.GONE);
        tvStatusText.setText("状态：上传中");
        tvHardeningStatus.setText("等待中");
        btnStartHardening.setEnabled(false);
        isProcessing = true;

        UploadManager.get().upload(this, selectedFileUri, selectedFileName, language,
                level, stringEncrypt, commentRemove, controlFlow,
                new UploadManager.UploadCallback() {
                    @Override
                    public void onProgress(int percent) {
                        progressUpload.setProgress(percent);
                        tvUploadPercent.setText(percent + "%");
                    }

                    @Override
                    public void onSuccess(String taskId) {
                        currentTaskId = taskId;
                        tvStatusText.setText("状态：加固中");
                        progressUpload.setProgress(100);
                        tvUploadPercent.setText("100%");
                        startPolling();
                    }

                    @Override
                    public void onFailure(String error) {
                        isProcessing = false;
                        btnStartHardening.setEnabled(true);
                        tvStatusText.setText("状态：上传失败");
                        tvHardeningStatus.setText(error);
                        toast("上传失败：" + error);
                    }
                });
    }

    private int selectedLevel() {
        int id = radioGroupLevel.getCheckedRadioButtonId();
        if (id == R.id.rbLevel1) return 1;
        if (id == R.id.rbLevel2) return 2;
        if (id == R.id.rbLevel3) return 3;
        return 1;
    }

    private void resetProgressUI() {
        progressUpload.setProgress(0);
        tvUploadPercent.setText("0%");
        progressHardening.setProgress(0);
        tvHardeningStatus.setText("等待中");
    }

    private void startPolling() {
        if (TextUtils.isEmpty(currentTaskId)) {
            return;
        }
        pollHandler.postDelayed(this::pollTaskStatus, POLL_INTERVAL_MS);
    }

    private void pollTaskStatus() {
        if (TextUtils.isEmpty(currentTaskId) || isFinishing()) {
            return;
        }
        statusCall = ApiClient.get().getTaskStatus(currentTaskId);
        statusCall.enqueue(new Callback<ApiResponse<TaskStatus>>() {
            @Override
            public void onResponse(Call<ApiResponse<TaskStatus>> call,
                                   Response<ApiResponse<TaskStatus>> response) {
                if (response.body() != null && response.body().isSuccess()
                        && response.body().getData() != null) {
                    updateTaskUI(response.body().getData());
                }
            }

            @Override
            public void onFailure(Call<ApiResponse<TaskStatus>> call, Throwable t) {
                // 网络抖动，继续轮询
                if (!isFinishing() && !TextUtils.isEmpty(currentTaskId)) {
                    pollHandler.postDelayed(MainActivity.this::pollTaskStatus, POLL_INTERVAL_MS);
                }
            }
        });
    }

    private void updateTaskUI(TaskStatus status) {
        lastStatus = status;
        int progress = Math.max(0, Math.min(100, status.getProgress()));
        progressHardening.setProgress(progress);

        String statusLabel = mapStatusLabel(status.getStatus());
        tvHardeningStatus.setText(statusLabel + "  " + progress + "%");
        tvStatusText.setText("状态：" + statusLabel);

        if (status.isTerminal()) {
            isProcessing = false;
            btnStartHardening.setEnabled(true);
            if (status.isCompleted()) {
                showResult(status);
            } else {
                toast("加固失败：" + (TextUtils.isEmpty(status.getErrorMsg())
                        ? "未知错误" : status.getErrorMsg()));
            }
        } else {
            // 继续轮询
            pollHandler.postDelayed(this::pollTaskStatus, POLL_INTERVAL_MS);
        }
    }

    private String mapStatusLabel(String status) {
        if (TaskStatus.WAITING.equals(status)) return "等待中";
        if (TaskStatus.PROCESSING.equals(status)) return "处理中";
        if (TaskStatus.COMPLETED.equals(status)) return "已完成";
        if (TaskStatus.FAILED.equals(status)) return "失败";
        return status;
    }

    private void showResult(TaskStatus status) {
        cardProgress.setVisibility(View.GONE);
        cardResult.setVisibility(View.VISIBLE);

        StringBuilder sb = new StringBuilder();
        sb.append("文件名：").append(safe(status.getFileName())).append("\n");
        sb.append("语言：").append(safe(status.getLanguage())).append("\n");
        sb.append("原始大小：").append(status.formatSize(status.getOriginalSize())).append("\n");
        sb.append("加固后大小：").append(status.formatSize(status.getHardenedSize())).append("\n");
        sb.append("耗时：").append(formatDuration(status.getDuration())).append("\n");
        sb.append("混淆率：").append(String.format(Locale.getDefault(),
                "%.1f%%", status.getObfuscationRate()));
        tvStats.setText(sb.toString());
    }

    private String safe(String s) {
        return s == null ? "-" : s;
    }

    private String formatDuration(long seconds) {
        if (seconds <= 0) return "-";
        if (seconds < 60) return seconds + " 秒";
        return (seconds / 60) + " 分 " + (seconds % 60) + " 秒";
    }

    private void downloadResult() {
        if (lastStatus == null || TextUtils.isEmpty(lastStatus.getDownloadUrl())) {
            toast("下载地址不可用");
            return;
        }
        String fileName = TextUtils.isEmpty(selectedFileName)
                ? "hardened_" + currentTaskId
                : "hardened_" + selectedFileName;
        long id = DownloadManager.get().download(this, lastStatus.getDownloadUrl(), fileName);
        if (id > 0) {
            toast("开始下载，文件将保存到 Download 目录");
        } else {
            toast("下载失败，请稍后重试");
        }
    }

    private void doLogout() {
        ApiClient.get().logout().enqueue(new Callback<ApiResponse<Void>>() {
            @Override
            public void onResponse(Call<ApiResponse<Void>> call,
                                   Response<ApiResponse<Void>> response) {
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
        toast("已退出登录");
        goToLogin();
    }

    private void goToLogin() {
        Intent intent = new Intent(this, LoginActivity.class);
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
        startActivity(intent);
        finish();
    }

    private void showRiskDialog(String riskText) {
        new AlertDialog.Builder(this)
                .setTitle("检测到风险环境")
                .setMessage("为保护代码安全，本应用不允许在 Root 或模拟器环境中运行：\n\n"
                        + riskText)
                .setCancelable(false)
                .setPositiveButton("退出", (d, w) -> finishAffinity())
                .show();
    }

    private void toast(String msg) {
        Toast.makeText(this, msg, Toast.LENGTH_SHORT).show();
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        pollHandler.removeCallbacksAndMessages(null);
        if (statusCall != null && !statusCall.isCanceled()) {
            statusCall.cancel();
        }
    }
}
