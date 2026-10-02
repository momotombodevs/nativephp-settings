package dev.momotombo.plugins.nativephp_settings

import android.content.Context
import com.nativephp.mobile.bridge.BridgeError
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject
import org.json.JSONTokener

private const val PREFERENCES_NAME = "dev.momotombo.nativephp.settings"
private const val STORAGE_KEY_PREFIX = "dev.momotombo.nativephp.settings."
private const val STRING_PREFIX = "mnp-settings:v1:s:"
private const val DOUBLE_PREFIX = "mnp-settings:v1:d:"
private const val JSON_PREFIX = "mnp-settings:v1:j:"
private const val MAX_VALUE_BYTES = 65_536
private const val MAX_NESTING_DEPTH = 32

/**
 * NativePHP bridge handlers for device-local settings.
 * Keep method names, payloads, and validation limits aligned with PHP and iOS.
 */
object SettingsFunctions {

    class Set(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val key = settingKey(parameters)
            if (!parameters.containsKey("value")) {
                invalidParameters("value is required.")
            }

            val value = normalizeValue(parameters["value"])
            val encoded = jsonString(value)
            if (encoded.toByteArray(Charsets.UTF_8).size > MAX_VALUE_BYTES) {
                invalidParameters("value exceeds the 65536 byte JSON limit.")
            }

            val storageKey = STORAGE_KEY_PREFIX + key
            val editor = preferences(context).edit()
            when (value) {
                is String -> editor.putString(storageKey, STRING_PREFIX + value)
                is Boolean -> editor.putBoolean(storageKey, value)
                is Long -> editor.putLong(storageKey, value)
                is Double -> editor.putString(storageKey, DOUBLE_PREFIX + java.lang.Double.toString(value))
                is Map<*, *>, is List<*> -> editor.putString(storageKey, JSON_PREFIX + encoded)
                else -> invalidParameters("value contains an unsupported type.")
            }

            if (!editor.commit()) {
                throw BridgeError.ExecutionFailed("Unable to persist the setting.")
            }

            return BridgeResponse.success(mapOf("status" to "ok"))
        }
    }

    class Get(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val key = settingKey(parameters)
            val storageKey = STORAGE_KEY_PREFIX + key
            val preferences = preferences(context)

            if (!preferences.contains(storageKey)) {
                return BridgeResponse.success(mapOf("found" to false))
            }

            return BridgeResponse.success(mapOf(
                "found" to true,
                "value" to decodeStoredValue(preferences.all[storageKey])
            ))
        }
    }

    class Has(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val storageKey = STORAGE_KEY_PREFIX + settingKey(parameters)
            return BridgeResponse.success(mapOf("found" to preferences(context).contains(storageKey)))
        }
    }

    class Forget(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val storageKey = STORAGE_KEY_PREFIX + settingKey(parameters)
            if (!preferences(context).edit().remove(storageKey).commit()) {
                throw BridgeError.ExecutionFailed("Unable to persist the setting removal.")
            }

            return BridgeResponse.success(mapOf("status" to "ok"))
        }
    }

    class All(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val values = linkedMapOf<String, Any>()

            preferences(context).all.forEach { (storageKey, storedValue) ->
                if (storageKey.startsWith(STORAGE_KEY_PREFIX)) {
                    val key = storageKey.removePrefix(STORAGE_KEY_PREFIX)
                    validateKey(key)
                    values[key] = decodeStoredValue(storedValue)
                }
            }

            return BridgeResponse.success(mapOf("values" to values))
        }
    }
}

private fun preferences(context: Context) = context.getSharedPreferences(PREFERENCES_NAME, Context.MODE_PRIVATE)

private fun settingKey(parameters: Map<String, Any>): String {
    val key = parameters["key"] as? String ?: invalidParameters("key is required.")
    validateKey(key)
    return key
}

private fun validateKey(key: String) {
    if (!Regex("^[a-z0-9_]+(?:\\.[a-z0-9_]+)*$").matches(key)) {
        invalidParameters("key must use lowercase dot notation.")
    }
}

private fun normalizeValue(value: Any?, depth: Int = 0): Any {
    if (depth > MAX_NESTING_DEPTH) {
        invalidParameters("value cannot be nested more than 32 levels.")
    }

    return when (value) {
        null, JSONObject.NULL -> invalidParameters("null values are not supported.")
        is String, is Boolean -> value
        is Byte, is Short, is Int, is Long -> (value as Number).toLong()
        is Float -> {
            if (!value.isFinite()) invalidParameters("value must contain finite numbers.")
            value.toDouble()
        }
        is Double -> {
            if (!value.isFinite()) invalidParameters("value must contain finite numbers.")
            value
        }
        is JSONObject -> {
            ensureContainerDepth(depth)
            val normalized = linkedMapOf<String, Any>()
            val keys = value.keys()
            while (keys.hasNext()) {
                val key = keys.next()
                normalized[key] = normalizeValue(value.get(key), depth + 1)
            }
            normalized
        }
        is JSONArray -> {
            ensureContainerDepth(depth)
            (0 until value.length()).map { index -> normalizeValue(value.get(index), depth + 1) }
        }
        is Map<*, *> -> {
            ensureContainerDepth(depth)
            val normalized = linkedMapOf<String, Any>()
            value.forEach { (key, item) ->
                val stringKey = key as? String ?: invalidParameters("array object keys must be strings.")
                normalized[stringKey] = normalizeValue(item, depth + 1)
            }
            normalized
        }
        is List<*> -> {
            ensureContainerDepth(depth)
            value.map { normalizeValue(it, depth + 1) }
        }
        else -> invalidParameters("value contains an unsupported type.")
    }
}

private fun ensureContainerDepth(depth: Int) {
    if (depth >= MAX_NESTING_DEPTH) {
        invalidParameters("value cannot be nested more than 32 levels.")
    }
}

private fun jsonString(value: Any): String = try {
    when (value) {
        is String -> JSONObject.quote(value)
        is Boolean, is Number -> value.toString()
        is Map<*, *> -> JSONObject(value).toString()
        is List<*> -> JSONArray(value).toString()
        else -> invalidParameters("value contains an unsupported type.")
    }
} catch (exception: JSONException) {
    throw BridgeError.InvalidParameters("value must be representable as valid JSON.")
}

private fun decodeStoredValue(value: Any?): Any {
    val decoded = when (value) {
        is Boolean, is Long -> value
        is String -> when {
            value.startsWith(STRING_PREFIX) -> value.removePrefix(STRING_PREFIX)
            value.startsWith(DOUBLE_PREFIX) -> value.removePrefix(DOUBLE_PREFIX).toDoubleOrNull()
                ?.takeIf(Double::isFinite)
                ?: corruptStoredValue()
            value.startsWith(JSON_PREFIX) -> {
                val jsonValue = try {
                    val tokener = JSONTokener(value.removePrefix(JSON_PREFIX))
                    val parsed = tokener.nextValue()
                    if (tokener.nextClean() != '\u0000') corruptStoredValue()
                    parsed
                } catch (_: JSONException) {
                    corruptStoredValue()
                }
                if (jsonValue !is JSONObject && jsonValue !is JSONArray) corruptStoredValue()
                normalizeStoredValue(jsonValue)
            }
            else -> corruptStoredValue()
        }
        else -> corruptStoredValue()
    }

    val encoded = try {
        jsonString(decoded)
    } catch (_: BridgeError) {
        corruptStoredValue()
    }

    if (encoded.toByteArray(Charsets.UTF_8).size > MAX_VALUE_BYTES) corruptStoredValue()
    return decoded
}

private fun normalizeStoredValue(value: Any?): Any = try {
    normalizeValue(value)
} catch (_: BridgeError) {
    corruptStoredValue()
}

private fun corruptStoredValue(): Nothing = throw BridgeError.ExecutionFailed("Stored value is malformed or unsupported.")

private fun invalidParameters(details: String): Nothing = throw BridgeError.InvalidParameters(details)
