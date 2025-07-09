# 🎨 Laravel Identicon Generator

A customizable and efficient **identicon avatar generator** built in Laravel. It uses hashes to generate symmetrical, deterministic avatars based on a seed (e.g., username or email). Supports caching, color configuration, and multiple output formats.

---

## 🚀 Features

* Generate unique identicon images from any string
* Supports PNG, JPEG, and GIF formats
* Background color customization
* Caching using Laravel’s cache system
* Input validation with error responses
* Returns proper image headers with caching metadata
* Optional info endpoint for debugging

---

## 🛠 Installation

1. Clone or add this to your Laravel app.

```

---

## 🌐 Usage

### ➕ Generate Identicon

```
GET /avatar/{seed}
```

#### 🔧 Optional Query Parameters

| Parameter    | Type   | Default | Description                                 |
| ------------ | ------ | ------- | ------------------------------------------- |
| `size`       | int    | 250     | Image size in pixels (50–500)               |
| `format`     | string | png     | Image format: `png`, `jpg`, `gif`           |
| `background` | string | ffffff  | Background color (hex, with or without `#`) |

#### 🧪 Example

```
GET /avatar/test@example.com?size=200&format=png&background=eeeeee
```

Response: `image/png` (or format specified)

---

### 🔍 Get Identicon Info

```
GET /avatar/{seed}/info
```

Returns JSON containing:

* SHA256 hash of the seed
* Hex and RGB color derived from the hash
* Cache key used internally

#### 🧪 Example

```
GET /avatar/bedan@example.com
```

Response:

```json
{
  "seed": "test@example.com",
  "hash": "d3b8...dce",
  "color": {
    "hex": "#64a3e4",
    "rgb": { "r": 100, "g": 163, "b": 228 }
  },
  "cache_key": "identicon:1a2b3c..."
}
```

---

## 🧹 Caching

* Avatars are cached using Laravel’s default cache store.
* Cache TTL: **1 hour** (3600 seconds)
* Uses a unique cache key based on seed, size, format, and background.

---

## 💥 Error Responses

| Status | Message                           |
| ------ | --------------------------------- |
| 400    | Validation failed (invalid input) |
| 500    | Internal server error             |

---

## 📂 Example Blade Usage

```blade
<img src="{{ url('/avatar/' . $user->email) }}?size=200&format=png" alt="Avatar">
```

---

## 📦 Requirements

* Laravel 8+
* PHP GD extension (enabled by default)

---

## 🧪 Tips for Testing

You can test it locally using Laravel's dev server:

```bash
php artisan serve --port=8001
```

Then visit:

```
http://127.0.0.1:8001/avatar/bedan@example.com?format=png&background=eee
```

---

## 🛡 License

MIT
