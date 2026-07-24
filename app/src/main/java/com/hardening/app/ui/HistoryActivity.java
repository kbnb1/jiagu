package com.hardening.app.ui;

import android.content.Intent;
import android.os.Bundle;
import android.text.TextUtils;
import android.view.View;
import android.widget.ProgressBar;
import android.widget.Toast;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;
import androidx.appcompat.widget.Toolbar;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;

import com.google.android.material.bottomnavigation.BottomNavigationView;
import com.hardening.app.R;
import com.hardening.app.db.TaskHistoryDao;
import com.hardening.app.model.ApiResponse;
import com.hardening.app.model.TaskHistory;
import com.hardening.app.network.ApiClient;
import com.hardening.app.network.DownloadManager;
import com.hardening.app.network.TokenManager;

import java.util.ArrayList;
import java.util.List;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

/**
 * 历史记录页。
 * - RecyclerView 展示历史任务
 * - 下拉刷新（SwipeRefreshLayout）
 * - 分页加载（滚动到底部自动加载更多）
 * - 点击查看 / 重新下载
 * - 长按删除（同步服务端 + 本地 DB）
 * - 空数据占位图
 * - 数据来源：本地 DB 缓存 + 服务端同步
 */
public class HistoryActivity extends AppCompatActivity
        implements HistoryAdapter.OnHistoryListener {

    private static final int PAGE_SIZE = 20;

    private SwipeRefreshLayout swipeRefresh;
    private RecyclerView recyclerView;
    private View layoutEmpty;
    private ProgressBar progressLoadMore;
    private BottomNavigationView bottomNav;

    private HistoryAdapter adapter;
    private TaskHistoryDao dao;

    private int currentPage = 1;
    private boolean isLoading = false;
    private boolean hasMore = true;
    private Call<ApiResponse<List<TaskHistory>>> historyCall;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        if (!TokenManager.get(this).isLoggedIn()) {
            startActivity(new Intent(this, LoginActivity.class)
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK));
            finish();
            return;
        }

        setContentView(R.layout.activity_history);

        Toolbar toolbar = findViewById(R.id.toolbar);
        toolbar.setNavigationOnClickListener(v -> finish());

        swipeRefresh = findViewById(R.id.swipeRefresh);
        recyclerView = findViewById(R.id.recyclerView);
        layoutEmpty = findViewById(R.id.layoutEmpty);
        progressLoadMore = findViewById(R.id.progressLoadMore);
        bottomNav = findViewById(R.id.bottomNav);

        dao = new TaskHistoryDao(this);
        adapter = new HistoryAdapter(this);

        recyclerView.setLayoutManager(new LinearLayoutManager(this));
        recyclerView.setAdapter(adapter);

        setupScrollListener();
        setupBottomNav();

        swipeRefresh.setOnRefreshListener(this::refresh);
        swipeRefresh.setColorSchemeResources(
                android.R.color.holo_blue_bright,
                android.R.color.holo_green_light,
                android.R.color.holo_orange_light);

        // 先显示本地缓存，再同步服务端
        loadLocalCache();
        refresh();
    }

    private void setupScrollListener() {
        recyclerView.addOnScrollListener(new RecyclerView.OnScrollListener() {
            @Override
            public void onScrolled(@NonNull RecyclerView rv, int dx, int dy) {
                if (dy <= 0 || isLoading || !hasMore) return;
                LinearLayoutManager lm = (LinearLayoutManager) rv.getLayoutManager();
                if (lm == null) return;
                int last = lm.findLastVisibleItemPosition();
                int total = lm.getItemCount();
                if (last >= total - 3) {
                    loadMore();
                }
            }
        });
    }

    private void setupBottomNav() {
        bottomNav.setSelectedItemId(R.id.nav_history);
        bottomNav.setOnItemSelectedListener(item -> {
            int id = item.getItemId();
            if (id == R.id.nav_history) {
                return true;
            } else if (id == R.id.nav_home) {
                startActivity(new Intent(this, MainActivity.class));
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

    private void loadLocalCache() {
        List<TaskHistory> local = dao.queryByPage(1, PAGE_SIZE);
        if (!local.isEmpty()) {
            adapter.setData(local);
            toggleEmpty(false);
        }
    }

    private void refresh() {
        currentPage = 1;
        hasMore = true;
        fetchPage(true);
    }

    private void loadMore() {
        if (isLoading || !hasMore) return;
        currentPage++;
        progressLoadMore.setVisibility(View.VISIBLE);
        fetchPage(false);
    }

    private void fetchPage(boolean isRefresh) {
        if (isLoading) return;
        isLoading = true;
        if (isRefresh && !swipeRefresh.isRefreshing()) {
            swipeRefresh.setRefreshing(true);
        }

        historyCall = ApiClient.get().getHistory(currentPage, PAGE_SIZE);
        historyCall.enqueue(new Callback<ApiResponse<List<TaskHistory>>>() {
            @Override
            public void onResponse(@NonNull Call<ApiResponse<List<TaskHistory>>> call,
                                   @NonNull Response<ApiResponse<List<TaskHistory>>> response) {
                isLoading = false;
                swipeRefresh.setRefreshing(false);
                progressLoadMore.setVisibility(View.GONE);

                List<TaskHistory> list = new ArrayList<>();
                if (response.body() != null && response.body().isSuccess()
                        && response.body().getData() != null) {
                    list = response.body().getData();
                }

                if (isRefresh) {
                    dao.clear();
                    dao.insertAll(list);
                    adapter.setData(list);
                } else {
                    dao.insertAll(list);
                    adapter.appendData(list);
                }

                hasMore = list.size() >= PAGE_SIZE;
                toggleEmpty(adapter.isEmpty());
            }

            @Override
            public void onFailure(@NonNull Call<ApiResponse<List<TaskHistory>>> call, Throwable t) {
                isLoading = false;
                swipeRefresh.setRefreshing(false);
                progressLoadMore.setVisibility(View.GONE);
                if (call.isCanceled()) return;
                toast("加载失败：" + t.getMessage());
                toggleEmpty(adapter.isEmpty());
            }
        });
    }

    private void toggleEmpty(boolean empty) {
        layoutEmpty.setVisibility(empty ? View.VISIBLE : View.GONE);
        recyclerView.setVisibility(empty ? View.GONE : View.VISIBLE);
    }

    @Override
    public void onItemClick(TaskHistory history, int position) {
        if (TextUtils.isEmpty(history.getDownloadUrl())) {
            toast("该任务暂无下载地址");
            return;
        }
        String fileName = "hardened_" + safe(history.getFileName(), history.getTaskId());
        long id = DownloadManager.get().download(this, history.getDownloadUrl(), fileName);
        toast(id > 0 ? "开始下载" : "下载失败");
    }

    @Override
    public void onItemLongClick(TaskHistory history, int position) {
        new AlertDialog.Builder(this)
                .setTitle("删除记录")
                .setMessage("确定删除该条历史记录吗？")
                .setPositiveButton("删除", (d, w) -> deleteHistory(history, position))
                .setNegativeButton("取消", null)
                .show();
    }

    private void deleteHistory(TaskHistory history, int position) {
        // 先本地删除
        dao.delete(history.getTaskId());
        adapter.removeAt(position);
        toggleEmpty(adapter.isEmpty());

        // 再同步服务端
        if (!TextUtils.isEmpty(history.getTaskId())) {
            ApiClient.get().deleteTask(history.getTaskId())
                    .enqueue(new Callback<ApiResponse<Void>>() {
                        @Override
                        public void onResponse(@NonNull Call<ApiResponse<Void>> call,
                                               @NonNull Response<ApiResponse<Void>> response) {
                        }

                        @Override
                        public void onFailure(@NonNull Call<ApiResponse<Void>> call, Throwable t) {
                        }
                    });
        }
        toast("已删除");
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
        if (historyCall != null && !historyCall.isCanceled()) {
            historyCall.cancel();
        }
    }
}
