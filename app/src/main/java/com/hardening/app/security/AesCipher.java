package com.hardening.app.security;

import android.util.Base64;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;

import java.nio.charset.StandardCharsets;
import java.security.SecureRandom;
import java.security.spec.KeySpec;

import javax.crypto.Cipher;
import javax.crypto.SecretKey;
import javax.crypto.SecretKeyFactory;
import javax.crypto.spec.IvParameterSpec;
import javax.crypto.spec.PBEKeySpec;
import javax.crypto.spec.SecretKeySpec;

/**
 * AES 加解密工具（AES-256-CBC + PKCS5Padding）。
 *
 * 用于本地敏感数据加密存储（如缓存的 token、用户配置等）。
 *
 * 密钥格式：Base64 编码的 32 字节（256 位）原始密钥，由 {@link #generateKey()} 或
 * {@link #deriveKey(String, String)} 生成。调用方需自行安全保存密钥。
 *
 * 密文格式：Base64( IV(16字节) + 密文 )，IV 每次加密随机生成，无需单独存储。
 *
 * PBKDF2 优先使用 HmacSHA256（API 26+），低版本回退 HmacSHA1，迭代次数 10000。
 */
public final class AesCipher {

    private static final String TRANSFORMATION = "AES/CBC/PKCS5Padding";
    private static final String AES = "AES";
    private static final int KEY_SIZE = 32;       // 256 bit
    private static final int IV_SIZE = 16;        // 128 bit
    private static final int PBKDF2_ITERATIONS = 10000;

    private AesCipher() {
    }

    /**
     * AES-256-CBC 加密。
     *
     * @param plainText 明文
     * @param keyBase64 Base64 编码的 32 字节密钥
     * @return Base64(IV + 密文)；出错返回 null
     */
    @Nullable
    public static String encrypt(@NonNull String plainText, @NonNull String keyBase64) {
        try {
            byte[] keyBytes = Base64.decode(keyBase64, Base64.NO_WRAP);
            byte[] iv = new byte[IV_SIZE];
            new SecureRandom().nextBytes(iv);

            Cipher cipher = Cipher.getInstance(TRANSFORMATION);
            cipher.init(Cipher.ENCRYPT_MODE,
                    new SecretKeySpec(keyBytes, AES),
                    new IvParameterSpec(iv));
            byte[] cipherText = cipher.doFinal(plainText.getBytes(StandardCharsets.UTF_8));

            // 拼接 IV + 密文
            byte[] output = new byte[iv.length + cipherText.length];
            System.arraycopy(iv, 0, output, 0, iv.length);
            System.arraycopy(cipherText, 0, output, iv.length, cipherText.length);
            return Base64.encodeToString(output, Base64.NO_WRAP);
        } catch (Exception e) {
            return null;
        }
    }

    /**
     * AES-256-CBC 解密。
     *
     * @param cipherBase64 {@link #encrypt} 产生的 Base64 密文
     * @param keyBase64    Base64 编码的 32 字节密钥
     * @return 明文；出错返回 null
     *
     * 重要：调用方必须检查返回值是否为 null！
     * 解密失败可能原因：
     * - 密钥错误或不匹配
     * - 密文格式错误或数据损坏
     * - 密文被篡改
     * - Base64 解码失败
     */
    @Nullable
    public static String decrypt(@NonNull String cipherBase64, @NonNull String keyBase64) {
        try {
            byte[] keyBytes = Base64.decode(keyBase64, Base64.NO_WRAP);
            byte[] all = Base64.decode(cipherBase64, Base64.NO_WRAP);
            if (all.length < IV_SIZE) {
                return null;
            }
            byte[] iv = new byte[IV_SIZE];
            byte[] cipherText = new byte[all.length - IV_SIZE];
            System.arraycopy(all, 0, iv, 0, IV_SIZE);
            System.arraycopy(all, IV_SIZE, cipherText, 0, cipherText.length);

            Cipher cipher = Cipher.getInstance(TRANSFORMATION);
            cipher.init(Cipher.DECRYPT_MODE,
                    new SecretKeySpec(keyBytes, AES),
                    new IvParameterSpec(iv));
            byte[] plain = cipher.doFinal(cipherText);
            return new String(plain, StandardCharsets.UTF_8);
        } catch (Exception e) {
            return null;
        }
    }

    /**
     * 生成随机 256 位 AES 密钥。
     *
     * @return Base64 编码的密钥
     */
    @NonNull
    public static String generateKey() {
        byte[] key = new byte[KEY_SIZE];
        new SecureRandom().nextBytes(key);
        return Base64.encodeToString(key, Base64.NO_WRAP);
    }

    /**
     * 从密码派生 256 位密钥（PBKDF2）。
     * 优先使用 PBKDF2WithHmacSHA256（API 26+），低版本回退 HmacSHA1。
     *
     * @param password 用户密码
     * @param salt     盐值（建议每用户唯一）
     * @return Base64 编码的 32 字节密钥
     */
    @NonNull
    public static String deriveKey(@NonNull String password, @NonNull String salt) {
        try {
            return deriveWith("PBKDF2WithHmacSHA256", password, salt);
        } catch (Exception e) {
            // API < 26 回退
            return deriveWith("PBKDF2WithHmacSHA1", password, salt);
        }
    }

    @NonNull
    private static String deriveWith(@NonNull String algorithm,
                                     @NonNull String password, @NonNull String salt) throws Exception {
        SecretKeyFactory factory = SecretKeyFactory.getInstance(algorithm);
        KeySpec spec = new PBEKeySpec(password.toCharArray(),
                salt.getBytes(StandardCharsets.UTF_8),
                PBKDF2_ITERATIONS, KEY_SIZE * 8);
        SecretKey tmp = factory.generateSecret(spec);
        return Base64.encodeToString(tmp.getEncoded(), Base64.NO_WRAP);
    }
}
