package com.hardening.app.model;

import com.google.gson.annotations.SerializedName;

/**
 * 用户信息。
 * 对应后端 users 表：id, username, password_hash, created_at。
 * 注意：password_hash 不下发到客户端，故此处不声明该字段。
 */
public class User {

    @SerializedName("id")
    private long id;

    @SerializedName("username")
    private String username;

    @SerializedName("created_at")
    private String createdAt;

    public long getId() {
        return id;
    }

    public String getUsername() {
        return username;
    }

    public String getCreatedAt() {
        return createdAt;
    }
}
