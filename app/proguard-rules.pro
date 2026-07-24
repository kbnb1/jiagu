# ==========================================================================
# 代码加固 App ProGuard 规则
# ==========================================================================

# -------------------- 通用：保留注解、泛型签名、源文件名 --------------------
-keepattributes *Annotation*
-keepattributes Signature
-keepattributes SourceFile,LineNumberTable
-keepattributes RuntimeVisibleAnnotations,RuntimeInvisibleAnnotations
-keepattributes EnclosingMethod,InnerClasses

# -------------------- model 包（Gson 反序列化依赖字段名） --------------------
# Gson 通过反射读取 @SerializedName 与字段名，必须保留全部字段。
-keep class com.hardening.app.model.** { *; }

# 保留 SerializeName 注解本身
-keep class com.google.gson.annotations.SerializedName { *; }
-keepclassmembers class * {
    @com.google.gson.annotations.SerializedName <fields>;
}

# -------------------- Parcelable（TaskStatus 等） --------------------
-keepclassmembers class * implements android.os.Parcelable {
    public static final android.os.Parcelable$Creator CREATOR;
}
-keep class com.hardening.app.model.TaskStatus { *; }
-keep class com.hardening.app.model.TaskStatus$TaskOptions { *; }

# -------------------- Retrofit / OkHttp / Gson --------------------
# Retrofit 接口方法由注解驱动，保留 ApiService 及其方法签名
-keep,allowobfuscation interface com.hardening.app.network.ApiService
-keepclassmembers,allowshrinking,allowobfuscation interface com.hardening.app.network.ApiService {
    @retrofit2.http.* <methods>;
}

# Retrofit 注解
-dontwarn retrofit2.**
-keep class retrofit2.** { *; }
-keepclasseswithmembers class * {
    @retrofit2.http.* <methods>;
}

# OkHttp / Okio
-dontwarn okhttp3.**
-dontwarn okio.**
-dontwarn org.conscrypt.**
-dontwarn org.bouncycastle.**
-dontwarn org.openjsse.**

# Gson TypeToken（泛型反序列化）
-keep class com.google.gson.reflect.TypeToken { *; }
-keep class * extends com.google.gson.reflect.TypeToken

# -------------------- security 包：加强混淆 --------------------
# security 包不添加 -keep，让 ProGuard 充分混淆类名与方法名，
# 增加逆向分析 AesCipher / SecurityCheck 的难度。
# 仅保留 native 方法（如有）与 JNI 调用所需的符号。
-keepclasseswithmembernames class * {
    native <methods>;
}

# -------------------- 其他 --------------------
# 保留 BuildConfig（部分逻辑依赖版本号字段）
-keep class com.hardening.app.BuildConfig { *; }
