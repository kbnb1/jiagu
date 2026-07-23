package com.hardening.app.model;

import com.google.gson.annotations.SerializedName;

/**
 * 登录请求体。JSON：{"username":"tom","password":"123456"}
 */
public class LoginRequest {

    @SerializedName("username")
    private final String username;

    @SerializedName("password")
    private final String password;

    public LoginRequest(String username, String password) {
        this.username = username;
        this.password = password;
    }
}
