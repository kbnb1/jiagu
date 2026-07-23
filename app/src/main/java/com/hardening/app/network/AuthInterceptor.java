package com.hardening.app.network;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;

import java.io.IOException;

import okhttp3.Interceptor;
import okhttp3.Request;
import okhttp3.Response;
import retrofit2.Call;
import com.hardening.app.model.ApiResponse;
import com.hardening.app.model.AuthResponse;
import com.hardening.app.model.RefreshTokenRequest;

/**
 * 鉴权拦截器：自动附加 Bearer Token，并在收到 401 时用 refresh_token 同步刷新后重试一次。
 *
 * 关键点：
 * 1. login / register / refresh 三个接口不附加 Token，也不触发刷新。
 * 2. 401 重试通过自定义头 X-Retry-Auth 标记，确保只重试一次，避免死循环。
 * 3. 刷新过程用 synchronized 串行化，多个并发请求 401 时只刷新一次，其余线程直接用新 Token 重试。
 * 4. 刷新失败（refresh_token 也过期）则清空本地凭证，让上层感知未登录状态。
 */
public class AuthInterceptor implements Interceptor {

    /** 标记请求已做过一次 401 重试，避免无限重试。 */
    private static final String HEADER_RETRY = "X-Retry-Auth";

    /** 用于发起 refresh 请求的“纯净”ApiService（本身不经过本拦截器，防止递归）。 */
    private final ApiService refreshApi;
    private final TokenManager tokenManager;
    private final Object refreshLock = new Object();

    public AuthInterceptor(@NonNull TokenManager tokenManager, @NonNull ApiService refreshApi) {
        this.tokenManager = tokenManager;
        this.refreshApi = refreshApi;
    }

    @NonNull
    @Override
    public Response intercept(@NonNull Chain chain) throws IOException {
        Request original = chain.request();
        String path = original.url().encodedPath();

        boolean isAuthEndpoint = path.contains("/user/login")
                || path.contains("/user/register")
                || path.contains("/user/refresh");

        // 1. 为业务接口附加 Bearer Token
        Request request = original;
        if (!isAuthEndpoint) {
            String token = tokenManager.getAccessToken();
            if (token != null) {
                request = original.newBuilder()
                        .header("Authorization", "Bearer " + token)
                        .build();
            }
        }

        Response response = chain.proceed(request);

        // 2. 非 401 直接放行
        if (response.code() != 401) {
            return response;
        }
        // 鉴权接口的 401 表示账号错误，不刷新
        if (isAuthEndpoint) {
            return response;
        }
        // 已重试过一次仍 401，放弃，交由上层处理
        if (original.header(HEADER_RETRY) != null) {
            return response;
        }

        // 3. 进入串行刷新流程
        synchronized (refreshLock) {
            String usedToken = bearerOf(request);
            String storedToken = tokenManager.getAccessToken();

            // 若本请求所用 Token 与当前存储的不同，说明已被其他线程刷新过，直接用新 Token 重试
            if (storedToken == null || storedToken.equals(usedToken)) {
                // 真正执行刷新
                if (!doRefresh()) {
                    // refresh_token 也失效：清空凭证，返回 401
                    tokenManager.clear();
                    return response;
                }
            }

            // 4. 用新 Token 重建请求并重试一次
            response.close();
            String newToken = tokenManager.getAccessToken();
            Request retryRequest = request.newBuilder()
                    .header("Authorization", "Bearer " + newToken)
                    .header(HEADER_RETRY, "1")
                    .build();
            return chain.proceed(retryRequest);
        }
    }

    /**
     * 同步调用 refresh 接口换取新 Token。
     * @return true 表示刷新成功并已写入 TokenManager。
     */
    private boolean doRefresh() {
        String refreshToken = tokenManager.getRefreshToken();
        if (refreshToken == null) {
            return false;
        }
        try {
            Call<ApiResponse<AuthResponse>> call =
                    refreshApi.refreshToken(new RefreshTokenRequest(refreshToken));
            retrofit2.Response<ApiResponse<AuthResponse>> resp = call.execute();
            if (!resp.isSuccessful() || resp.body() == null) {
                return false;
            }
            ApiResponse<AuthResponse> body = resp.body();
            if (!body.isSuccess() || body.getData() == null) {
                return false;
            }
            AuthResponse auth = body.getData();
            tokenManager.updateTokens(auth.getAccessToken(), auth.getRefreshToken());
            return true;
        } catch (IOException e) {
            return false;
        }
    }

    /** 从请求头中提取 Bearer Token 原始值（用于判断是否已被其他线程刷新）。 */
    @Nullable
    private String bearerOf(Request request) {
        String header = request.header("Authorization");
        if (header != null && header.startsWith("Bearer ")) {
            return header.substring(7);
        }
        return null;
    }
}
