package com.hardening.app.ui;

import android.content.Context;
import android.graphics.Color;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.core.content.ContextCompat;
import androidx.recyclerview.widget.RecyclerView;

import com.hardening.app.R;
import com.hardening.app.model.TaskHistory;
import com.hardening.app.model.TaskStatus;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.concurrent.TimeUnit;

/**
 * 历史任务列表适配器。
 * - 不同状态使用不同颜色标识
 * - 语言通过系统图标区分
 * - 时间相对格式化（刚刚 / x 分钟前 / x 小时前 / x 天前）
 * - 暴露点击与长按删除回调
 */
public class HistoryAdapter extends RecyclerView.Adapter<HistoryAdapter.HistoryVH> {

    public interface OnHistoryListener {
        void onItemClick(TaskHistory history, int position);
        void onItemLongClick(TaskHistory history, int position);
    }

    private final List<TaskHistory> data = new ArrayList<>();
    private final OnHistoryListener listener;
    private final java.text.SimpleDateFormat dateFormat =
            new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm", Locale.getDefault());

    public HistoryAdapter(OnHistoryListener listener) {
        this.listener = listener;
    }

    public void setData(List<TaskHistory> list) {
        data.clear();
        if (list != null) {
            data.addAll(list);
        }
        notifyDataSetChanged();
    }

    public void appendData(List<TaskHistory> list) {
        if (list == null || list.isEmpty()) return;
        int start = data.size();
        data.addAll(list);
        notifyItemRangeInserted(start, list.size());
    }

    public void removeAt(int position) {
        if (position < 0 || position >= data.size()) return;
        data.remove(position);
        notifyItemRemoved(position);
        notifyItemRangeChanged(position, data.size() - position);
    }

    public List<TaskHistory> getData() {
        return data;
    }

    public boolean isEmpty() {
        return data.isEmpty();
    }

    @NonNull
    @Override
    public HistoryVH onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
        View view = LayoutInflater.from(parent.getContext())
                .inflate(R.layout.item_history, parent, false);
        return new HistoryVH(view);
    }

    @Override
    public void onBindViewHolder(@NonNull HistoryVH holder, int position) {
        TaskHistory h = data.get(position);
        Context ctx = holder.itemView.getContext();

        holder.tvTaskNo.setText("#" + (position + 1));
        holder.tvFileName.setText(safe(h.getFileName(), "未命名文件"));
        holder.tvLanguage.setText(safe(h.getLanguage(), "-"));
        holder.ivIcon.setImageResource(languageIcon(h.getLanguage()));
        holder.tvTime.setText(formatRelativeTime(h.getCreatedAt()));

        applyStatusStyle(ctx, holder, h.getStatus());
    }

    private void applyStatusStyle(Context ctx, HistoryVH holder, String status) {
        int color;
        String label;
        if (TaskStatus.COMPLETED.equals(status)) {
            color = ContextCompat.getColor(ctx, android.R.color.holo_green_dark);
            label = "已完成";
        } else if (TaskStatus.FAILED.equals(status)) {
            color = ContextCompat.getColor(ctx, android.R.color.holo_red_dark);
            label = "失败";
        } else if (TaskStatus.PROCESSING.equals(status)) {
            color = ContextCompat.getColor(ctx, android.R.color.holo_blue_dark);
            label = "处理中";
        } else if (TaskStatus.WAITING.equals(status)) {
            color = ContextCompat.getColor(ctx, android.R.color.holo_orange_dark);
            label = "等待中";
        } else {
            color = Color.parseColor("#888888");
            label = safe(status, "未知");
        }
        holder.tvStatus.setText(label);
        holder.tvStatus.setTextColor(color);
    }

    private int languageIcon(String language) {
        if (language == null) return android.R.drawable.ic_menu_help;
        switch (language) {
            case "Java":
                return android.R.drawable.ic_menu_compass;
            case "PHP":
                return android.R.drawable.ic_menu_agenda;
            case "JavaScript":
                return android.R.drawable.ic_menu_sort_by_size;
            case "Python":
                return android.R.drawable.ic_menu_gallery;
            case "C++":
                return android.R.drawable.ic_menu_manage;
            default:
                return android.R.drawable.ic_menu_help;
        }
    }

    private String formatRelativeTime(long timestamp) {
        if (timestamp <= 0) return "-";
        long diff = System.currentTimeMillis() - timestamp;
        if (diff < 0) return "刚刚";
        long minutes = TimeUnit.MILLISECONDS.toMinutes(diff);
        if (minutes < 1) return "刚刚";
        if (minutes < 60) return minutes + " 分钟前";
        long hours = TimeUnit.MILLISECONDS.toHours(diff);
        if (hours < 24) return hours + " 小时前";
        long days = TimeUnit.MILLISECONDS.toDays(diff);
        if (days < 30) return days + " 天前";
        return dateFormat.format(new java.util.Date(timestamp));
    }

    private String safe(String s, String def) {
        return (s == null || s.isEmpty()) ? def : s;
    }

    @Override
    public int getItemCount() {
        return data.size();
    }

    class HistoryVH extends RecyclerView.ViewHolder {
        ImageView ivIcon;
        TextView tvTaskNo;
        TextView tvFileName;
        TextView tvLanguage;
        TextView tvStatus;
        TextView tvTime;

        HistoryVH(@NonNull View itemView) {
            super(itemView);
            ivIcon = itemView.findViewById(R.id.ivLangIcon);
            tvTaskNo = itemView.findViewById(R.id.tvTaskNo);
            tvFileName = itemView.findViewById(R.id.tvFileName);
            tvLanguage = itemView.findViewById(R.id.tvLanguage);
            tvStatus = itemView.findViewById(R.id.tvStatus);
            tvTime = itemView.findViewById(R.id.tvTime);

            itemView.setOnClickListener(v -> {
                int pos = getBindingAdapterPosition();
                if (pos != RecyclerView.NO_POSITION && listener != null) {
                    listener.onItemClick(data.get(pos), pos);
                }
            });
            itemView.setOnLongClickListener(v -> {
                int pos = getBindingAdapterPosition();
                if (pos != RecyclerView.NO_POSITION && listener != null) {
                    listener.onItemLongClick(data.get(pos), pos);
                    return true;
                }
                return false;
            });
        }
    }
}
