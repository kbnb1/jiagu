package com.hardening.app.security;

import android.content.Context;
import android.content.pm.PackageManager;
import android.os.Build;

import java.io.File;
import java.util.ArrayList;
import java.util.List;

/**
 * 环境安全检测：启动时判断当前设备是否 Root 或运行在模拟器中。
 * 任一风险命中即视为不安全，App 弹窗阻止使用，避免加固产物在风险环境被逆向抓取。
 *
 * 检测维度（多重交叉验证，降低误判）：
 *  - Root：su / busybox 二进制路径、root 管理类应用、test-keys 构建标签；
 *  - 模拟器：Build 特征字段、QEMU 属性、主流模拟器专属文件、无传感器等。
 */
public class SecurityCheck {

    /** 常见 su / magisk 路径。 */
    private static final String[] SU_PATHS = {
            "/system/bin/su", "/system/xbin/su", "/sbin/su",
            "/system/sd/xbin/su", "/system/bin/failsafe/su",
            "/data/local/xbin/su", "/data/local/bin/su", "/data/local/su",
            "/su/bin/su", "/magisk/.core/bin/su"
    };

    /** 常见 Root 管理应用包名。 */
    private static final String[] ROOT_APPS = {
            "com.topjohnwu.magisk", "eu.chainfire.supersu",
            "com.koushikdutta.superuser", "com.thirdparty.superuser",
            "com.noshufou.android.su", "com.kingouser.com",
            "com.kingroot.kingroot"
    };

    /** 模拟器专属文件路径。 */
    private static final String[] EMU_FILES = {
            "/dev/socket/qemud", "/dev/qemu_pipe",
            "/system/lib/libc_malloc_debug_qemu.so",
            "/sys/qemu_trace", "/system/bin/qemu-props"
    };

    private final Context context;

    public SecurityCheck(Context context) {
        this.context = context.getApplicationContext();
    }

    /** 综合判定：Root 或 模拟器 任一命中即不安全。 */
    public Result check() {
        List<String> risks = new ArrayList<>();
        if (isRooted(risks)) {
            risks.add(0, "检测到 Root 权限");
        }
        if (isEmulator(risks)) {
            risks.add("运行环境疑似模拟器");
        }
        boolean safe = risks.isEmpty();
        return new Result(safe, risks);
    }

    // -------------------- Root 检测 --------------------

    private boolean isRooted(List<String> risks) {
        // 1. 构建标签为 test-keys
        if (Build.TAGS != null && Build.TAGS.contains("test-keys")) {
            return true;
        }
        // 2. 存在 su / magisk 二进制
        for (String path : SU_PATHS) {
            if (new File(path).exists()) {
                return true;
            }
        }
        // 3. 安装了 Root 管理类应用
        PackageManager pm = context.getPackageManager();
        for (String pkg : ROOT_APPS) {
            try {
                pm.getPackageInfo(pkg, 0);
                return true;
            } catch (PackageManager.NameNotFoundException ignored) {
                // 未安装该包，继续
            }
        }
        // 4. which su 命令可执行（兜底，部分机型 su 不在固定路径）
        return canExecuteSu();
    }

    /** 尝试执行 `which su`，能找到则认为已 Root。 */
    private boolean canExecuteSu() {
        Process process = null;
        try {
            process = Runtime.getRuntime().exec(new String[]{"which", "su"});
            java.io.InputStream is = process.getInputStream();
            // 读取输出：有内容说明 su 存在于 PATH
            byte[] buf = new byte[256];
            int read = is.read(buf);
            return read != -1;
        } catch (Exception e) {
            return false;
        } finally {
            if (process != null) {
                process.destroy();
            }
        }
    }

    // -------------------- 模拟器检测 --------------------

    private boolean isEmulator(List<String> risks) {
        int hits = 0;

        // 1. Build 特征字段
        String brand = safe(Build.BRAND);
        String model = safe(Build.MODEL);
        String product = safe(Build.PRODUCT);
        String hardware = safe(Build.HARDWARE);
        String fingerprint = safe(Build.FINGERPRINT);
        String manufacturer = safe(Build.MANUFACTURER);

        if (product.contains("sdk") || product.contains("google_sdk")
                || product.contains("sdk_x86") || product.contains("sdk_gphone64")) {
            hits++;
        }
        if (model.contains("google_sdk") || model.contains("Emulator")
                || model.contains("Android SDK") || model.contains("sdk_gphone")) {
            hits++;
        }
        if (hardware.contains("goldfish") || hardware.contains("ranchu")
                || hardware.contains("vbox86gl")) {
            hits++;
        }
        if (fingerprint.contains("generic") || fingerprint.contains("unknown")
                || fingerprint.contains("emulator")) {
            hits++;
        }
        if (brand.startsWith("generic") || manufacturer.contains("Genymotion")
                || manufacturer.contains("unknown")) {
            hits++;
        }

        // 2. QEMU 系统属性
        if ("1".equals(getSystemProperty("ro.kernel.qemu"))) {
            hits++;
        }

        // 3. 模拟器专属文件
        for (String path : EMU_FILES) {
            if (new File(path).exists()) {
                hits++;
                break;
            }
        }

        // 4. 主流国产模拟器包名（Bluestacks / Nox / MEmu / LDPlayer 等）
        if (hasEmulatorApp()) {
            hits++;
        }

        // 命中 2 项及以上才判定为模拟器，降低单特征误判
        return hits >= 2;
    }

    private boolean hasEmulatorApp() {
        String[] emuPkgs = {
                "com.bluestacks", "com.bluestacks.home",
                "com.bignox.app", "com.bignox.app.store.hometool",
                "com.microvirt.launcher", "com.microvirt.markethome",
                "com.xb.www"
        };
        PackageManager pm = context.getPackageManager();
        for (String pkg : emuPkgs) {
            try {
                pm.getPackageInfo(pkg, 0);
                return true;
            } catch (PackageManager.NameNotFoundException ignored) {
            }
        }
        return false;
    }

    private String getSystemProperty(String key) {
        try {
            Class<?> clazz = Class.forName("android.os.SystemProperties");
            java.lang.reflect.Method get = clazz.getMethod("get", String.class);
            Object value = get.invoke(null, key);
            return value == null ? "" : value.toString();
        } catch (Exception e) {
            return "";
        }
    }

    private String safe(String s) {
        return s == null ? "" : s.toLowerCase();
    }

    /** 检测结果。 */
    public static class Result {
        public final boolean safe;
        public final List<String> risks;

        Result(boolean safe, List<String> risks) {
            this.safe = safe;
            this.risks = risks;
        }

        public String riskText() {
            if (risks.isEmpty()) {
                return "";
            }
            StringBuilder sb = new StringBuilder();
            for (int i = 0; i < risks.size(); i++) {
                if (i > 0) sb.append("\n");
                sb.append("• ").append(risks.get(i));
            }
            return sb.toString();
        }
    }
}
