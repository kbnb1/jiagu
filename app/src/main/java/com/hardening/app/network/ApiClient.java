package com.hardening.app.network;

import android.content.Context;

import com.google.gson.FieldNamingPolicy;
import com.google.gson.Gson;
import com.google.gson.GsonBuilder;

import java.util.concurrent.TimeUnit;

import okhttp3.OkHttpClient;
import okhttp3.logging.HttpLoggingInterceptor;
import retrofit2.Retrofit;
import retrofit2.converter.gson.GsonConverterFactory;

/**
 * 网络层单例入口。
 *
 * 构建两个 OkHttp 客户端：
 *   1) plainClient —— 仅用于 refresh_token 请求，不挂任何鉴权拦截器，避免 401 递归刷新；
 *   2) mainClient  —— 业务客户端，挂载 AuthInterceptor（自动 Bearer + 401 刷新）与日志拦截器。
 *
 * Gson 统一使用 LOWER_CASE_WITH_UNDERSCORES 策略，使 Java 驼峰字段 ↔ JSON snake_case 自动映射。
 */
public class ApiClient {

    private static ApiService apiService;
    private static TokenManager tokenManager;

    private ApiClient() {
    }

    /** 在 Application.onCreate 中调用一次。 */
    public static void init(Context context, String baseUrl) {
        tokenManager = TokenManager.get(context);

        Gson gson = new GsonBuilder()
                .setFieldNamingPolicy(FieldNamingPolicy.LOWER_CASE_WITH_UNDERSCORES)
                .create();
        GsonConverterFactory converterFactory = GsonConverterFactory.create(gson);

        // 1. 纯净客户端：专供刷新 Token 使用
        OkHttpClient plainClient = new OkHttpClient.Builder()
                .connectTimeout(15, TimeUnit.SECONDS)
                .readTimeout(30, TimeUnit.SECONDS)
                .build();
        Retrofit plainRetrofit = new Retrofit.Builder()
                .baseUrl(baseUrl)
                .client(plainClient)
                .addConverterFactory(converterFactory)
                .build();
        ApiService refreshApi = plainRetrofit.create(ApiService.class);

        // 2. 业务客户端：挂载 AuthInterceptor
        AuthInterceptor authInterceptor = new AuthInterceptor(tokenManager, refreshApi);

        HttpLoggingInterceptor logging = new HttpLoggingInterceptor();
        // 生产环境应改为 Level.NONE，避免 Token 泄露到日志
        logging.setLevel(HttpLoggingInterceptor.Level.BODY);

        OkHttpClient mainClient = new OkHttpClient.Builder()
                .connectTimeout(15, TimeUnit.SECONDS)
                .readTimeout(60, TimeUnit.SECONDS)
                .writeTimeout(60, TimeUnit.SECONDS) // 上传大文件需要较长写超时
                .addInterceptor(authInterceptor)
                .addInterceptor(logging)
                .build();

        Retrofit retrofit = new Retrofit.Builder()
                .baseUrl(baseUrl)
                .client(mainClient)
                .addConverterFactory(converterFactory)
                .build();

        apiService = retrofit.create(ApiService.class);
    }

    /** 获取业务 ApiService。未初始化时抛出异常，便于尽早发现配置遗漏。 */
    public static ApiService get() {
        if (apiService == null) {
            throw new IllegalStateException("ApiClient 未初始化，请先在 Application 中调用 init()");
        }
        return apiService;
    }

    public static TokenManager tokens() {
        return tokenManager;
    }
}
