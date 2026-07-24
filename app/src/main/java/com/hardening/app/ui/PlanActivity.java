package com.hardening.app.ui;

import android.content.Intent;
import android.os.Bundle;
import android.text.TextUtils;
import android.view.LayoutInflater;
import android.view.View;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;
import androidx.appcompat.widget.Toolbar;

import com.google.android.material.bottomnavigation.BottomNavigationView;
import com.google.android.material.button.MaterialButton;
import com.hardening.app.R;
import com.hardening.app.model.ApiResponse;
import com.hardening.app.model.CreateOrderRequest;
import com.hardening.app.model.Plan;
import com.hardening.app.network.ApiClient;
import com.hardening.app.network.TokenManager;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.List;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

/**
 * 套餐页面。
 * - 展示所有套餐卡片（免费 / 基础 / 专业 / 企业）
 * - 当前套餐高亮显示
 * - "选择套餐"按钮创建订单
 * - 套餐对比表
 */
public class PlanActivity extends AppCompatActivity {

    private LinearLayout layoutPlans;
    private ProgressBar progressBar;
    private BottomNavigationView bottomNav;

    private Call<ApiResponse<List<Plan>>> plansCall;
    private Call<ApiResponse<Void>> orderCall;
    private final List<Plan> plans = new ArrayList<>();

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        if (!TokenManager.get(this).isLoggedIn()) {
            startActivity(new Intent(this, LoginActivity.class)
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK));
            finish();
            return;
        }

        setContentView(R.layout.activity_plan);

        Toolbar toolbar = findViewById(R.id.toolbar);
        toolbar.setNavigationOnClickListener(v -> finish());

        layoutPlans = findViewById(R.id.layoutPlans);
        progressBar = new ProgressBar(this);
        bottomNav = findViewById(R.id.bottomNav);

        setupBottomNav();
        loadPlans();
    }

    private void setupBottomNav() {
        bottomNav.setSelectedItemId(R.id.nav_plan);
        bottomNav.setOnItemSelectedListener(item -> {
            int id = item.getItemId();
            if (id == R.id.nav_plan) {
                return true;
            } else if (id == R.id.nav_home) {
                startActivity(new Intent(this, MainActivity.class));
                return false;
            } else if (id == R.id.nav_history) {
                startActivity(new Intent(this, HistoryActivity.class));
                return false;
            } else if (id == R.id.nav_profile) {
                startActivity(new Intent(this, ProfileActivity.class));
                return false;
            }
            return false;
        });
    }

    private void loadPlans() {
        plans.clear();
        plans.addAll(defaultPlans());
        renderPlans();

        plansCall = ApiClient.get().getPlans();
        plansCall.enqueue(new Callback<ApiResponse<List<Plan>>>() {
            @Override
            public void onResponse(@NonNull Call<ApiResponse<List<Plan>>> call,
                                   @NonNull Response<ApiResponse<List<Plan>>> response) {
                if (response.body() != null && response.body().isSuccess()
                        && response.body().getData() != null
                        && !response.body().getData().isEmpty()) {
                    plans.clear();
                    plans.addAll(response.body().getData());
                    renderPlans();
                }
            }

            @Override
            public void onFailure(@NonNull Call<ApiResponse<List<Plan>>> call, Throwable t) {
                // 网络失败时使用默认套餐数据
            }
        });
    }

    private List<Plan> defaultPlans() {
        List<Plan> list = new ArrayList<>();
        list.add(new Plan(1, "免费版", 0, 5,
                Arrays.asList("每日 5 次加固", "单文件 2MB 以内", "1 级混淆", "字符串加密", "社区支持")));
        list.add(new Plan(2, "基础版", 29, 50,
                Arrays.asList("每日 50 次加固", "单文件 10MB 以内", "3 级混淆", "全部加固选项", "邮件支持")));
        list.add(new Plan(3, "专业版", 99, 200,
                Arrays.asList("每日 200 次加固", "单文件 50MB 以内", "3 级混淆", "全部加固选项",
                        "优先处理队列", "邮件 + 工单支持")));
        list.add(new Plan(4, "企业版", 299, Integer.MAX_VALUE,
                Arrays.asList("无限加固次数", "单文件 200MB 以内", "3 级混淆", "全部加固选项",
                        "最高优先级", "专属客服", "SLA 保障")));
        // 默认标记免费版为当前套餐
        if (!list.isEmpty()) {
            list.get(0).setCurrent(true);
        }
        return list;
    }

    private void renderPlans() {
        layoutPlans.removeAllViews();
        for (Plan plan : plans) {
            View card = LayoutInflater.from(this).inflate(R.layout.item_plan, layoutPlans, false);
            bindPlanCard(card, plan);
            layoutPlans.addView(card);
        }
    }

    private void bindPlanCard(View card, Plan plan) {
        TextView tvName = card.findViewById(R.id.tvPlanName);
        TextView tvPrice = card.findViewById(R.id.tvPrice);
        TextView tvQuota = card.findViewById(R.id.tvQuota);
        TextView tvFeatures = card.findViewById(R.id.tvFeatures);
        TextView tvCurrent = card.findViewById(R.id.tvCurrent);
        MaterialButton btnSelect = card.findViewById(R.id.btnSelect);

        tvName.setText(safe(plan.getName(), "套餐"));
        tvPrice.setText(plan.priceText());
        tvQuota.setText("每日 " + (plan.getDailyQuota() == Integer.MAX_VALUE
                ? "无限" : plan.getDailyQuota() + " 次"));

        StringBuilder sb = new StringBuilder();
        if (plan.getFeatures() != null) {
            for (String f : plan.getFeatures()) {
                if (sb.length() > 0) sb.append("\n");
                sb.append("• ").append(f);
            }
        }
        tvFeatures.setText(sb.length() > 0 ? sb.toString() : "• 标准加固功能");

        if (plan.isCurrent()) {
            tvCurrent.setVisibility(View.VISIBLE);
            btnSelect.setText("当前套餐");
            btnSelect.setEnabled(false);
        } else {
            tvCurrent.setVisibility(View.GONE);
            btnSelect.setText("选择套餐");
            btnSelect.setEnabled(true);
            btnSelect.setOnClickListener(v -> confirmOrder(plan));
        }
    }

    private void confirmOrder(Plan plan) {
        new AlertDialog.Builder(this)
                .setTitle("确认订单")
                .setMessage("套餐：" + plan.getName() + "\n价格：" + plan.priceText()
                        + "\n\n确认购买该套餐吗？")
                .setPositiveButton("确认购买", (d, w) -> createOrder(plan))
                .setNegativeButton("取消", null)
                .show();
    }

    private void createOrder(Plan plan) {
        Toast.makeText(this, "正在创建订单...", Toast.LENGTH_SHORT).show();
        orderCall = ApiClient.get().createOrder(new CreateOrderRequest(plan.getId()));
        orderCall.enqueue(new Callback<ApiResponse<Void>>() {
            @Override
            public void onResponse(@NonNull Call<ApiResponse<Void>> call,
                                   @NonNull Response<ApiResponse<Void>> response) {
                if (response.body() != null && response.body().isSuccess()) {
                    Toast.makeText(PlanActivity.this,
                            "订单创建成功，请前往支付", Toast.LENGTH_LONG).show();
                    markCurrent(plan);
                } else {
                    String msg = response.body() == null ? "创建失败"
                            : response.body().getMessage();
                    Toast.makeText(PlanActivity.this,
                            "创建失败：" + msg, Toast.LENGTH_SHORT).show();
                }
            }

            @Override
            public void onFailure(@NonNull Call<ApiResponse<Void>> call, Throwable t) {
                Toast.makeText(PlanActivity.this,
                        "网络异常：" + t.getMessage(), Toast.LENGTH_SHORT).show();
            }
        });
    }

    private void markCurrent(Plan selected) {
        for (Plan p : plans) {
            p.setCurrent(p.getId() == selected.getId());
        }
        renderPlans();
    }

    private String safe(String s, String def) {
        return TextUtils.isEmpty(s) ? def : s;
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        if (plansCall != null && !plansCall.isCanceled()) {
            plansCall.cancel();
        }
        if (orderCall != null && !orderCall.isCanceled()) {
            orderCall.cancel();
        }
    }
}
