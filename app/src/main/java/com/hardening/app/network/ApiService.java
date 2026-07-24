package com.hardening.app.network;

import com.hardening.app.model.ApiResponse;
import com.hardening.app.model.AuthResponse;
import com.hardening.app.model.ChangePasswordRequest;
import com.hardening.app.model.CreateOrderRequest;
import com.hardening.app.model.LoginRequest;
import com.hardening.app.model.Order;
import com.hardening.app.model.Plan;
import com.hardening.app.model.RefreshTokenRequest;
import com.hardening.app.model.RegisterRequest;
import com.hardening.app.model.TaskHistory;
import com.hardening.app.model.TaskStatus;
import com.hardening.app.model.UpdateProfileRequest;
import com.hardening.app.model.User;
import com.hardening.app.model.UserAccount;

import java.util.List;

import okhttp3.MultipartBody;
import okhttp3.RequestBody;
import okhttp3.ResponseBody;
import retrofit2.Call;
import retrofit2.http.Body;
import retrofit2.http.DELETE;
import retrofit2.http.GET;
import retrofit2.http.Multipart;
import retrofit2.http.POST;
import retrofit2.http.PUT;
import retrofit2.http.Part;
import retrofit2.http.Path;
import retrofit2.http.Query;

/**
 * 后端接口定义。所有 JSON 字段均为 snake_case（由 Gson 自动转换）。
 * 鉴权相关接口无需 Bearer 头，业务接口由 AuthInterceptor 自动附加并处理 401。
 *
 * 接口分为两套：
 * 1. 规范接口（createTask / getTaskHistory / getCurrentPlan / getProfile …）——RESTful 风格，
 *    供 UploadManager / DownloadManager 及新业务模块使用；
 * 2. 兼容接口（uploadTask / getHistory / getUserAccount / createOrder …）——供已有 UI 页面调用，
 *    与规范接口功能重叠时以规范接口为准。
 */
public interface ApiService {

    // -------------------- 鉴权 --------------------

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

    // -------------------- 用户 --------------------

    /** 获取个人资料：GET /api/user/profile */
    @GET("api/user/profile")
    Call<ApiResponse<User>> getProfile();

    /** 更新个人资料：PUT /api/user/profile */
    @PUT("api/user/profile")
    Call<ApiResponse<User>> updateProfile(@Body UpdateProfileRequest body);

    /** 修改密码：PUT /api/user/password */
    @PUT("api/user/password")
    Call<ApiResponse<Void>> changePassword(@Body ChangePasswordRequest body);

    /** 获取当前用户账户信息：GET /api/user/account */
    @GET("api/user/account")
    Call<ApiResponse<UserAccount>> getUserAccount();

    // -------------------- 任务（规范接口） --------------------

    /**
     * 创建加固任务：POST /api/task/create
     * multipart 上传源码文件，language 与 options 作为表单字段。
     * options 为 TaskStatus.TaskOptions 序列化后的 JSON 字符串。
     */
    @Multipart
    @POST("api/task/create")
    Call<ApiResponse<TaskStatus>> createTask(@Part MultipartBody.Part file,
                                             @Part("language") RequestBody language,
                                             @Part("options") RequestBody options);

    /** 查询任务状态：GET /api/task/status/{id} */
    @GET("api/task/status/{id}")
    Call<ApiResponse<TaskStatus>> getTaskStatus(@Path("id") long id);

    /** 查询任务结果：GET /api/task/result/{id} */
    @GET("api/task/result/{id}")
    Call<ApiResponse<TaskStatus>> getTaskResult(@Path("id") long id);

    /** 下载加固产物：GET /api/task/download/{id}（返回二进制流） */
    @GET("api/task/download/{id}")
    Call<ResponseBody> downloadTask(@Path("id") long id);

    /** 任务历史：GET /api/task/history?page=&size= */
    @GET("api/task/history")
    Call<ApiResponse<List<TaskStatus>>> getTaskHistory(@Query("page") int page,
                                                       @Query("size") int size);

    /** 删除任务：DELETE /api/task/{id} */
    @DELETE("api/task/{id}")
    Call<ApiResponse<Void>> deleteTask(@Path("id") long id);

    // -------------------- 任务（兼容接口，供已有 UI 使用） --------------------

    /** 上传代码文件并提交加固任务：POST /api/task/upload */
    @Multipart
    @POST("api/task/upload")
    Call<ApiResponse<TaskStatus>> uploadTask(@Part MultipartBody.Part file,
                                             @Part("language") RequestBody language,
                                             @Part("obfuscation_level") RequestBody level,
                                             @Part("string_encrypt") RequestBody stringEncrypt,
                                             @Part("comment_remove") RequestBody commentRemove,
                                             @Part("control_flow") RequestBody controlFlow);

    /** 查询任务状态（按字符串任务号）：GET /api/task/status/{taskId} */
    @GET("api/task/status/{taskId}")
    Call<ApiResponse<TaskStatus>> getTaskStatus(@Path("taskId") String taskId);

    /** 获取历史任务列表（返回 model.TaskHistory）：GET /api/task/history?page=&size= */
    @GET("api/task/history")
    Call<ApiResponse<List<TaskHistory>>> getHistory(@Query("page") int page,
                                                    @Query("size") int size);

    /** 删除历史任务（按字符串任务号）：DELETE /api/task/{taskId} */
    @DELETE("api/task/{taskId}")
    Call<ApiResponse<Void>> deleteTask(@Path("taskId") String taskId);

    // -------------------- 套餐 --------------------

    /** 套餐列表：GET /api/plan/list */
    @GET("api/plan/list")
    Call<ApiResponse<List<Plan>>> getPlans();

    /** 当前套餐：GET /api/plan/current */
    @GET("api/plan/current")
    Call<ApiResponse<UserAccount>> getCurrentPlan();

    /** 订单列表：GET /api/plan/orders */
    @GET("api/plan/orders")
    Call<ApiResponse<List<Order>>> getOrders();

    /** 创建套餐订单：POST /api/order/create */
    @POST("api/order/create")
    Call<ApiResponse<Void>> createOrder(@Body CreateOrderRequest body);
}
