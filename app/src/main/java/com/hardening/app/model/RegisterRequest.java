package com.hardening.app.model;

import com.google.gson.annotations.SerializedName;

/**
 * 注册请求体。JSON：{"username":"tom","password":"123456"}
 * 密码在传输层由 HTTPS 保护；服务端使用 password_hash (bcrypt) 存储。
 */
public class RegisterRequest {

    @SerializedName("username")
    private final String username;

    @SerializedName("password")
    private final String password;

    public RegisterRequest(String username, String password) {
        this.username = username;
        this.password = password;
    }
}
