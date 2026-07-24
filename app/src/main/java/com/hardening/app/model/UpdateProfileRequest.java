package com.hardening.app.model;

import com.google.gson.annotations.SerializedName;

/**
 * 更新用户资料请求体。JSON：{"email":"a@b.com","phone":"13800000000"}
 * email / phone 均可选，仅传入需要修改的字段。
 */
public class UpdateProfileRequest {

    @SerializedName("email")
    private final String email;

    @SerializedName("phone")
    private final String phone;

    public UpdateProfileRequest(String email, String phone) {
        this.email = email;
        this.phone = phone;
    }
}
