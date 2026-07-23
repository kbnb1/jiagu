package com.hardening.app.network;

import com.hardening.app.model.ApiResponse;
import com.hardening.app.model.AuthResponse;
import com.hardening.app.model.LoginRequest;
import com.hardening.app.model.RefreshTokenRequest;
import com.hardening.app.model.RegisterRequest;

import retrofit2.Call;
import retrofit2.http.Body;
import retrofit2.http.POST;

/**
 * 后端接口定义。所有 JSON 字段均为 snake_case（由 Gson 自动转换）。
 * 鉴权相关接口无需 Bearer 头，业务接口由 AuthInterceptor 自动附加并处理 401。
 */
public interface ApiService {

    /** 用户注册：POST /api/user/register */
    @POST("api/user/register")
    Call<ApiResponse<AuthResponse>> register(@Body RegisterRequest body);

    /** 用户登录：POST /api/user/login */
    @POST("api/user/login")
    Call<ApiResponse<AuthResponse>> login(@Body LoginRequest body);

    /**
     * 刷新 Token：POST /api/user/refresh
     * 该接口用 refresh_token 换取新的 access_token / refresh_token。
     * 由 AuthInterceptor 在 401 时同步调用，App 业务层一般不直接调用。
     */
    @POST("api/user/refresh")
    Call<ApiResponse<AuthResponse>> refreshToken(@Body RefreshTokenRequest body);

    /** 退出登录：POST /api/user/logout（使服务端 refresh_token 失效） */
    @POST("api/user/logout")
    Call<ApiResponse<Void>> logout();
}
