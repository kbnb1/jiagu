package com.hardening.app.network;

import android.content.Context;
import android.content.SharedPreferences;

/**
 * Token 持久化管理（基于 SharedPreferences）。
 * 负责保存 / 读取 access_token 与 refresh_token，以及当前登录用户信息。
 * access_token 有效期 7 天；过期或 401 时由 AuthInterceptor 用 refresh_token 自动刷新。
 */
public class TokenManager {

    private static final String PREF_NAME = "hardening_auth";
    private static final String KEY_ACCESS_TOKEN = "access_token";
    private static final String KEY_REFRESH_TOKEN = "refresh_token";
    private static final String KEY_USER_ID = "user_id";
    private static final String KEY_USERNAME = "username";

    private static TokenManager instance;
    private final SharedPreferences prefs;

    private TokenManager(Context context) {
        // 使用 applicationContext 避免内存泄漏
        prefs = context.getApplicationContext()
                .getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE);
    }

    public static synchronized TokenManager get(Context context) {
        if (instance == null) {
            instance = new TokenManager(context);
        }
        return instance;
    }

    /** 登录 / 注册成功后保存整套凭证。 */
    public void saveAuth(String accessToken, String refreshToken, long userId, String username) {
        prefs.edit()
                .putString(KEY_ACCESS_TOKEN, accessToken)
                .putString(KEY_REFRESH_TOKEN, refreshToken)
                .putLong(KEY_USER_ID, userId)
                .putString(KEY_USERNAME, username)
                .apply();
    }

    /** 仅刷新 token（401 自动刷新时调用）。 */
    public void updateTokens(String accessToken, String refreshToken) {
        prefs.edit()
                .putString(KEY_ACCESS_TOKEN, accessToken)
                .putString(KEY_REFRESH_TOKEN, refreshToken)
                .apply();
    }

    public String getAccessToken() {
        return prefs.getString(KEY_ACCESS_TOKEN, null);
    }

    public String getRefreshToken() {
        return prefs.getString(KEY_REFRESH_TOKEN, null);
    }

    public long getUserId() {
        return prefs.getLong(KEY_USER_ID, -1L);
    }

    public String getUsername() {
        return prefs.getString(KEY_USERNAME, null);
    }

    public boolean isLoggedIn() {
        return getAccessToken() != null;
    }

    /** 退出登录或 refresh 失败时清空全部凭证。 */
    public void clear() {
        prefs.edit().clear().apply();
    }
}
