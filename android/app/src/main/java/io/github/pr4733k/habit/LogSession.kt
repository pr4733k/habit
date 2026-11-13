package io.github.pr4733k.habit

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import java.time.LocalDate
import java.time.ZoneId
import java.util.UUID
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

internal data class HabitConnection(val origin: String, val zone: ZoneId, val id: String) {
    fun today(): LocalDate = LocalDate.now(zone)
}

internal class LogSession(context: Context) {
    val preferences = context.getSharedPreferences("habit_connection", Context.MODE_PRIVATE)

    fun connection(): HabitConnection? = synchronized(connectionLock) {
        val origin = preferences.getString("origin", null) ?: return@synchronized null
        val zone = preferences.getString("zone", null) ?: return@synchronized null
        val id = preferences.getString("id", null) ?: return@synchronized null
        HabitConnection(origin, ZoneId.of(zone), id)
    }

    fun isSignedIn() = preferences.contains("cookie") && System.currentTimeMillis() < preferences.getLong("expires", 0)

    fun read(connection: HabitConnection): String? {
        if (connection != connection() || !isSignedIn()) return null
        val saved = preferences.getString("cookie", null) ?: return null
        return runCatching {
            val data = Base64.decode(saved, Base64.NO_WRAP)
            val key = keyStore().getKey(alias, null) as SecretKey
            val cipher = Cipher.getInstance("AES/GCM/NoPadding")
            cipher.init(Cipher.DECRYPT_MODE, key, GCMParameterSpec(128, data.copyOfRange(0, 12)))
            cipher.updateAAD(connection.id.toByteArray(Charsets.UTF_8))
            cipher.doFinal(data.copyOfRange(12, data.size)).toString(Charsets.UTF_8)
                .takeIf(sessionValuePattern::matches)
        }.getOrNull()
    }

    fun connect(origin: String, zone: ZoneId, cookie: String): HabitConnection {
        val connection = HabitConnection(origin, zone, UUID.randomUUID().toString())
        store(connection, cookie, replace = true)
        return connection
    }

    fun write(connection: HabitConnection, cookie: String) = store(connection, cookie, replace = false)

    private fun store(connection: HabitConnection, cookie: String, replace: Boolean) = synchronized(connectionLock) {
        if (!replace && connection != connection()) return@synchronized
        val key = keyStore().getKey(alias, null) as? SecretKey
            ?: KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").run {
                init(
                    KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                        .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                        .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                        .build(),
                )
                generateKey()
            }
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key)
        cipher.updateAAD(connection.id.toByteArray(Charsets.UTF_8))
        val data = cipher.iv + cipher.doFinal(cookie.toByteArray(Charsets.UTF_8))
        preferences.edit()
            .putString("origin", connection.origin)
            .putString("zone", connection.zone.id)
            .putString("id", connection.id)
            .putString("cookie", Base64.encodeToString(data, Base64.NO_WRAP))
            .putLong("expires", System.currentTimeMillis() + 365L * 24 * 60 * 60 * 1000)
            .apply()
    }

    fun clear(connection: HabitConnection) = synchronized(connectionLock) {
        if (connection == connection()) preferences.edit().remove("cookie").remove("expires").apply()
    }

    private fun keyStore() = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }

    private companion object {
        const val alias = "HabitLogSession"
        val connectionLock = Any()
    }
}
