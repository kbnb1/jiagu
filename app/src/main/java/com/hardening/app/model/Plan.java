package com.hardening.app.model;

import com.google.gson.annotations.SerializedName;

import java.util.ArrayList;
import java.util.List;

/**
 * 套餐信息。
 */
public class Plan {

    @SerializedName("id")
    private int id;

    @SerializedName("name")
    private String name;

    @SerializedName("price")
    private double price;

    @SerializedName("daily_quota")
    private int dailyQuota;

    @SerializedName("features")
    private List<String> features = new ArrayList<>();

    @SerializedName("is_current")
    private boolean current;

    public Plan() {
    }

    public Plan(int id, String name, double price, int dailyQuota, List<String> features) {
        this.id = id;
        this.name = name;
        this.price = price;
        this.dailyQuota = dailyQuota;
        this.features = features;
    }

    public int getId() { return id; }
    public void setId(int id) { this.id = id; }

    public String getName() { return name; }
    public void setName(String name) { this.name = name; }

    public double getPrice() { return price; }
    public void setPrice(double price) { this.price = price; }

    public int getDailyQuota() { return dailyQuota; }
    public void setDailyQuota(int dailyQuota) { this.dailyQuota = dailyQuota; }

    public List<String> getFeatures() { return features; }
    public void setFeatures(List<String> features) { this.features = features; }

    public boolean isCurrent() { return current; }
    public void setCurrent(boolean current) { this.current = current; }

    public String priceText() {
        return price <= 0 ? "免费" : "¥" + (int) price + "/月";
    }
}
