package com.hardening.app.model;

import com.google.gson.annotations.SerializedName;

/**
 * 刷新 Token 请求体。JSON：{"refresh_token":"yyy"}
 * 由 AuthInterceptor 在 401 时自动发起，业务层通常不直接构造。
 */
public class RefreshTokenRequest {

    @SerializedName("refresh_token")
    private final String refreshToken;

    public RefreshTokenRequest(String refreshToken) {
        this.refreshToken = refreshToken;
    }
}
