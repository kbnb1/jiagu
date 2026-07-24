package com.hardening.app.ui;

import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.LinearLayout;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.recyclerview.widget.RecyclerView;
import androidx.viewpager2.widget.ViewPager2;

import com.google.android.material.button.MaterialButton;
import com.hardening.app.R;
import com.hardening.app.network.TokenManager;

/**
 * 引导页（首次安装显示）。
 * 3 页 ViewPager：代码加固 / 多语言支持 / 安全保护。
 * 最后一页显示"立即体验"按钮，完成后标记已读。
 */
public class GuideActivity extends AppCompatActivity {

    private static final String PREF_NAME = "app_prefs";
    private static final String KEY_GUIDE_SHOWN = "guide_shown";

    private ViewPager2 viewPager;
    private LinearLayout dotsLayout;
    private MaterialButton btnExperience;
    private ImageView[] dots;

    private final int[] layouts = {
            R.layout.guide_item_1,
            R.layout.guide_item_2,
            R.layout.guide_item_3
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_guide);

        viewPager = findViewById(R.id.viewPager);
        dotsLayout = findViewById(R.id.dotsLayout);
        btnExperience = findViewById(R.id.btnExperience);

        GuidePagerAdapter adapter = new GuidePagerAdapter();
        viewPager.setAdapter(adapter);

        setupDots(0);
        btnExperience.setVisibility(View.GONE);

        viewPager.registerOnPageChangeCallback(new ViewPager2.OnPageChangeCallback() {
            @Override
            public void onPageSelected(int position) {
                selectDot(position);
                btnExperience.setVisibility(
                        position == layouts.length - 1 ? View.VISIBLE : View.GONE);
            }
        });

        btnExperience.setOnClickListener(v -> finishGuide());
    }

    private void setupDots(int current) {
        dotsLayout.removeAllViews();
        dots = new ImageView[layouts.length];
        int margin = (int) (8 * getResources().getDisplayMetrics().density);
        for (int i = 0; i < layouts.length; i++) {
            dots[i] = new ImageView(this);
            LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.WRAP_CONTENT,
                    ViewGroup.LayoutParams.WRAP_CONTENT);
            params.setMargins(margin, 0, margin, 0);
            dots[i].setLayoutParams(params);
            dots[i].setImageResource(android.R.drawable.presence_invisible);
            dotsLayout.addView(dots[i]);
        }
        selectDot(current);
    }

    private void selectDot(int position) {
        if (dots == null) return;
        for (int i = 0; i < dots.length; i++) {
            int res = (i == position)
                    ? android.R.drawable.presence_online
                    : android.R.drawable.presence_invisible;
            dots[i].setImageResource(res);
        }
    }

    private void finishGuide() {
        getSharedPreferences(PREF_NAME, MODE_PRIVATE)
                .edit()
                .putBoolean(KEY_GUIDE_SHOWN, true)
                .apply();

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

    private class GuidePagerAdapter extends RecyclerView.Adapter<GuidePagerAdapter.PageViewHolder> {

        @NonNull
        @Override
        public PageViewHolder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            View view = LayoutInflater.from(parent.getContext()).inflate(viewType, parent, false);
            return new PageViewHolder(view);
        }

        @Override
        public void onBindViewHolder(@NonNull PageViewHolder holder, int position) {
            // 静态内容，无需绑定
        }

        @Override
        public int getItemCount() {
            return layouts.length;
        }

        @Override
        public int getItemViewType(int position) {
            return layouts[position];
        }

        class PageViewHolder extends RecyclerView.ViewHolder {
            PageViewHolder(@NonNull View itemView) {
                super(itemView);
            }
        }
    }
}
