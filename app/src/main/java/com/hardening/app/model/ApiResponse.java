package com.hardening.app.model;

import com.google.gson.annotations.SerializedName;

/**
 * 后端统一响应包装。
 * JSON 形如：{"code": 0, "message": "ok", "data": {...}}
 * code == 0 表示成功，其余值为业务错误码。
 *
 * @param <T> data 字段的实际类型
 */
public class ApiResponse<T> {

    @SerializedName("code")
    private int code;

    @SerializedName("message")
    private String message;

    @SerializedName("data")
    private T data;

    public int getCode() {
        return code;
    }

    public String getMessage() {
        return message;
    }

    public T getData() {
        return data;
    }

    public boolean isSuccess() {
        return code == 0;
    }
}
