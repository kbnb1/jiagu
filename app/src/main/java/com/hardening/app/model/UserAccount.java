package com.hardening.app.model;

import com.google.gson.annotations.SerializedName;

/**
 * 用户账户信息（个人中心展示用）。
 */
public class UserAccount {

    @SerializedName("username")
    private String username;

    @SerializedName("created_at")
    private String createdAt;

    @SerializedName("plan_name")
    private String planName;

    @SerializedName("used_quota")
    private int usedQuota;

    @SerializedName("total_quota")
    private int totalQuota;

    @SerializedName("avatar_url")
    private String avatarUrl;

    public String getUsername() { return username; }
    public void setUsername(String username) { this.username = username; }

    public String getCreatedAt() { return createdAt; }
    public void setCreatedAt(String createdAt) { this.createdAt = createdAt; }

    public String getPlanName() { return planName; }
    public void setPlanName(String planName) { this.planName = planName; }

    public int getUsedQuota() { return usedQuota; }
    public void setUsedQuota(int usedQuota) { this.usedQuota = usedQuota; }

    public int getTotalQuota() { return totalQuota; }
    public void setTotalQuota(int totalQuota) { this.totalQuota = totalQuota; }

    public String getAvatarUrl() { return avatarUrl; }
    public void setAvatarUrl(String avatarUrl) { this.avatarUrl = avatarUrl; }

    public int quotaPercent() {
        if (totalQuota <= 0) return 0;
        return (int) (usedQuota * 100.0 / totalQuota);
    }
}
