package com.hardening.app.model;

import com.google.gson.annotations.SerializedName;

/**
 * 订单模型：套餐购买记录。
 * /api/plan/orders 返回订单列表，用于“我的订单”页面。
 */
public class Order {

    @SerializedName("id")
    private long id;

    @SerializedName("order_no")
    private String orderNo;

    @SerializedName("plan_name")
    private String planName;

    @SerializedName("amount")
    private double amount;

    @SerializedName("status")
    private String status;

    @SerializedName("created_at")
    private String createdAt;

    public long getId() {
        return id;
    }

    public String getOrderNo() {
        return orderNo;
    }

    public String getPlanName() {
        return planName;
    }

    public double getAmount() {
        return amount;
    }

    public String getStatus() {
        return status;
    }

    public String getCreatedAt() {
        return createdAt;
    }
}
