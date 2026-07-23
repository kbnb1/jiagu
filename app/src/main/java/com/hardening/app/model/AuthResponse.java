package com.hardening.app.model;

import com.google.gson.annotations.SerializedName;

/**
 * 登录 / 注册 / 刷新 Token 成功后返回的鉴权数据。
 * JSON 形如：
 * {"access_token":"xxx","refresh_token":"yyy","user":{"id":1,"username":"tom","created_at":"..."}}
 *
 * access_token 有效期 7 天；过期后用 refresh_token 换新。
 */
public class AuthResponse {

    @SerializedName("access_token")
    private String accessToken;

    @SerializedName("refresh_token")
    private String refreshToken;

    @SerializedName("user")
    private User user;

    public String getAccessToken() {
        return accessToken;
    }

    public String getRefreshToken() {
        return refreshToken;
    }

    public User getUser() {
        return user;
    }
}
