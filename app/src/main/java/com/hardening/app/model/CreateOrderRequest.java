package com.hardening.app.model;

import com.google.gson.annotations.SerializedName;

/**
 * 创建套餐订单请求体。
 */
public class CreateOrderRequest {

    @SerializedName("plan_id")
    private int planId;

    public CreateOrderRequest(int planId) {
        this.planId = planId;
    }

    public int getPlanId() { return planId; }
}
