import CoreFoundation
import Foundation

private let storageKeyPrefix = "dev.momotombo.nativephp.settings."
private let stringPrefix = "mnp-settings:v1:s:"
private let doublePrefix = "mnp-settings:v1:d:"
private let jsonPrefix = "mnp-settings:v1:j:"
private let maxValueBytes = 65_536
private let maxNestingDepth = 32

/// NativePHP bridge handlers for device-local settings.
/// Keep method names, payloads, and validation limits aligned with PHP and Android.
enum SettingsFunctions {

    class Set: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let key = try settingKey(parameters)
            guard let rawValue = parameters["value"] else {
                throw BridgeError.invalidParameters("value is required.")
            }

            let value = try normalizeValue(rawValue)
            let encoded = try jsonData(value)
            guard encoded.count <= maxValueBytes else {
                throw BridgeError.invalidParameters("value exceeds the 65536 byte JSON limit.")
            }

            let storageKey = storageKeyPrefix + key
            let defaults = UserDefaults.standard

            if type(of: value) == String.self, let string = value as? String {
                defaults.set(stringPrefix + string, forKey: storageKey)
            } else if type(of: value) == Bool.self, let boolean = value as? Bool {
                defaults.set(boolean, forKey: storageKey)
            } else if type(of: value) == Int64.self, let integer = value as? Int64 {
                defaults.set(NSNumber(value: integer), forKey: storageKey)
            } else if type(of: value) == Double.self, let double = value as? Double {
                defaults.set(doublePrefix + String(double), forKey: storageKey)
            } else if value is [Any] || value is [String: Any] {
                defaults.set(jsonPrefix + String(decoding: encoded, as: UTF8.self), forKey: storageKey)
            } else {
                throw BridgeError.invalidParameters("value contains an unsupported type.")
            }

            return BridgeResponse.success(data: ["status": "ok"])
        }
    }

    class Get: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let storageKey = storageKeyPrefix + (try settingKey(parameters))
            let settings = try persistedSettings()

            guard let storedValue = settings[storageKey] else {
                return BridgeResponse.success(data: ["found": false])
            }

            return BridgeResponse.success(data: [
                "found": true,
                "value": try decodeStoredValue(storedValue),
            ])
        }
    }

    class Has: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let storageKey = storageKeyPrefix + (try settingKey(parameters))
            let settings = try persistedSettings()
            return BridgeResponse.success(data: ["found": settings[storageKey] != nil])
        }
    }

    class Forget: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let storageKey = storageKeyPrefix + (try settingKey(parameters))
            UserDefaults.standard.removeObject(forKey: storageKey)
            return BridgeResponse.success(data: ["status": "ok"])
        }
    }

    class All: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            var values: [String: Any] = [:]
            let storedSettings = try persistedSettings()

            for (storageKey, storedValue) in storedSettings
                where storageKey.hasPrefix(storageKeyPrefix) {
                let key = String(storageKey.dropFirst(storageKeyPrefix.count))
                try validateKey(key)
                values[key] = try decodeStoredValue(storedValue)
            }

            return BridgeResponse.success(data: ["values": values])
        }
    }
}

private func persistedSettings() throws -> [String: Any] {
    guard let domain = Bundle.main.bundleIdentifier else {
        throw BridgeError.executionFailed("The app settings storage domain is unavailable.")
    }

    return UserDefaults.standard.persistentDomain(forName: domain) ?? [:]
}

private func settingKey(_ parameters: [String: Any]) throws -> String {
    guard let key = parameters["key"] as? String else {
        throw BridgeError.invalidParameters("key is required.")
    }

    try validateKey(key)
    return key
}

private func validateKey(_ key: String) throws {
    guard key.range(of: "^[a-z0-9_]+(?:\\.[a-z0-9_]+)*$", options: .regularExpression) != nil else {
        throw BridgeError.invalidParameters("key must use lowercase dot notation.")
    }
}

private func normalizeValue(_ value: Any, depth: Int = 0) throws -> Any {
    guard depth <= maxNestingDepth else {
        throw BridgeError.invalidParameters("value cannot be nested more than 32 levels.")
    }

    if value is NSNull {
        throw BridgeError.invalidParameters("null values are not supported.")
    }

    if let string = value as? String {
        return string
    }

    if let dictionary = value as? [String: Any] {
        guard depth < maxNestingDepth else {
            throw BridgeError.invalidParameters("value cannot be nested more than 32 levels.")
        }

        var normalized: [String: Any] = [:]
        for (key, nestedValue) in dictionary {
            normalized[key] = try normalizeValue(nestedValue, depth: depth + 1)
        }
        return normalized
    }

    if let array = value as? [Any] {
        guard depth < maxNestingDepth else {
            throw BridgeError.invalidParameters("value cannot be nested more than 32 levels.")
        }

        return try array.map { try normalizeValue($0, depth: depth + 1) }
    }

    if let number = value as? NSNumber {
        if CFGetTypeID(number) == CFBooleanGetTypeID() {
            return number.boolValue
        }

        let numberType = String(cString: number.objCType)
        if numberType == "f" || numberType == "d" {
            let double = number.doubleValue
            guard double.isFinite else {
                throw BridgeError.invalidParameters("value must contain finite numbers.")
            }
            return double
        }

        return number.int64Value
    }

    throw BridgeError.invalidParameters("value contains an unsupported type.")
}

private func jsonData(_ value: Any) throws -> Data {
    do {
        return try JSONSerialization.data(withJSONObject: value, options: [.fragmentsAllowed, .sortedKeys])
    } catch {
        throw BridgeError.invalidParameters("value must be representable as valid JSON.")
    }
}

private func decodeStoredValue(_ value: Any) throws -> Any {
    if let boolean = value as? Bool, type(of: value) == Bool.self {
        return try validateStoredValueSize(boolean)
    }

    if let number = value as? NSNumber {
        if CFGetTypeID(number) == CFBooleanGetTypeID() {
            return try validateStoredValueSize(number.boolValue)
        }

        let numberType = String(cString: number.objCType)
        guard numberType != "f" && numberType != "d" else {
            throw BridgeError.executionFailed("Stored value is malformed or unsupported.")
        }

        return try validateStoredValueSize(number.int64Value)
    }

    guard let storedString = value as? String else {
        throw BridgeError.executionFailed("Stored value is malformed or unsupported.")
    }

    if storedString.hasPrefix(stringPrefix) {
        return try validateStoredValueSize(String(storedString.dropFirst(stringPrefix.count)))
    }

    if storedString.hasPrefix(doublePrefix) {
        guard let double = Double(storedString.dropFirst(doublePrefix.count)), double.isFinite else {
            throw BridgeError.executionFailed("Stored value is malformed or unsupported.")
        }
        return try validateStoredValueSize(double)
    }

    guard storedString.hasPrefix(jsonPrefix),
          let data = String(storedString.dropFirst(jsonPrefix.count)).data(using: .utf8) else {
        throw BridgeError.executionFailed("Stored value is malformed or unsupported.")
    }

    do {
        let decoded = try JSONSerialization.jsonObject(with: data, options: [.fragmentsAllowed])
        guard decoded is [Any] || decoded is [String: Any] else {
            throw BridgeError.executionFailed("Stored value is malformed or unsupported.")
        }
        return try validateStoredValueSize(normalizeValue(decoded))
    } catch {
        throw BridgeError.executionFailed("Stored value is malformed or unsupported.")
    }
}

private func validateStoredValueSize(_ value: Any) throws -> Any {
    let data: Data
    do {
        data = try jsonData(value)
    } catch {
        throw BridgeError.executionFailed("Stored value is malformed or unsupported.")
    }

    guard data.count <= maxValueBytes else {
        throw BridgeError.executionFailed("Stored value is malformed or unsupported.")
    }

    return value
}
