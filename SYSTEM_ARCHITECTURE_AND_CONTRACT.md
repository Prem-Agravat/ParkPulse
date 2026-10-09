# ParkPulse 2026 — Architecture, API Contract & Threat Model

**Smart Parking Reservation & Management System for Code2Web 2026**

---

## 1. High-Level Architecture & Layer Separation

The application follows a clean single-application MVC-Service architecture with separated concerns:

```
c:\xampp\htdocs\smart parking/
├── config/                  # Configuration & Environment
│   ├── db.php               # Singleton PDO connection (InnoDB, UTF-8, strict mode)
│   └── security.php         # CSRF, Auth, RBAC, Rate Limiting, Audit Logger
├── src/                     # Core Business Logic
│   ├── Models/              # Data Entity abstractions (User, Vehicle, Facility, Slot, Reservation, Payment)
│   ├── Services/            # Business logic (Auth, Facility, Slot, Reservation, Gate, Analytics)
│   ├── Controllers/         # Endpoint orchestrators & Request handlers
│   └── Middleware/          # RBAC, CSRF validation, Rate Limiting guards
├── api/                     # RESTful JSON Endpoints
│   ├── health.php           # System health & database connectivity probe
│   ├── facilities.php       # Facility search, geolocation & availability counts
│   ├── slots.php            # Dynamic floor/zone layout & slot availability
│   ├── scan-lookup.php      # Attendant pass & plate validation
│   ├── process-gate.php     # Barrier check-in and check-out processor
│   ├── cancel-booking.php   # Driver self-service cancellation & refund
│   ├── toggle-slot.php      # Maintenance lock override
│   └── admin-stats.php      # Aggregated metrics for Chart.js
├── database/                # Database Migrations & Seeds
│   ├── schema.sql           # InnoDB relational table definitions
│   └── seed.php             # Demo dataset seeder with realistic entities
├── assets/                  # Frontend Design System & Client JS
│   ├── css/style.css        # Modern design tokens, slot matrix, high-contrast ticket
│   └── js/main.js           # AJAX fetch wrapper, CSRF injector, toast & QR helpers
├── includes/                # Shared UI Shells
│   ├── header.php           # Semantic HTML5 head & CDNs
│   ├── navbar.php           # Role-based adaptive navigation
│   └── footer.php           # Scripts, Chart.js, Leaflet, HTML5-QRCode
├── tests/                   # Automated Verification
│   ├── system_test.php      # Backend unit & integration test suite (37 tests)
│   └── http_integration_test.php # HTTP session & cookie jar test suite (26 tests)
├── .env.example             # Configuration template (placeholders only)
├── .env                     # Local environment configuration (git ignored)
└── .htaccess                # Apache security headers & sensitive file protection
```

---

## 2. API Contract Specification

All JSON API endpoints return standard HTTP status codes and a consistent JSON payload structure:

```json
{
  "success": true,
  "message": "Human readable status message",
  "data": { ... }
}
```

### Endpoints Table

| Method | Endpoint | Auth Required | Expected Input | Success Code | Error Codes | Response Data |
|---|---|---|---|---|---|---|
| `GET` | `/api/health.php` | None | None | `200 OK` | `500 Server Error` | `{ "status": "UP", "db": "connected", "latency_ms": 1.2, "timestamp": "..." }` |
| `GET` | `/api/facilities.php` | None | `q`, `start_time`, `end_time`, `ev_only`, `city` | `200 OK` | `400 Bad Request` | List of facilities with live available slots count & pricing |
| `GET` | `/api/slots.php` | None | `facility_id` (int), `start_time`, `end_time` | `200 OK` | `400 Bad Request`, `404 Not Found` | Floor & Zone matrix with status (`available`, `occupied`, `reserved`, `maintenance`) |
| `POST` | `/api/cancel-booking.php` | User/Admin | `{ "booking_reference": "PK-...", "reason": "..." }` | `200 OK` | `400 Bad Request`, `403 Forbidden`, `404 Not Found` | `{ "refund_amount": 260.00 }` |
| `GET` | `/api/scan-lookup.php` | Attendant/Admin | `q` (QR token, Ref, or Plate) | `200 OK` | `400 Bad Request`, `403 Forbidden`, `404 Not Found` | Full booking details, driver phone, allocated stall, overtime calculations |
| `POST` | `/api/process-gate.php` | Attendant/Admin | `{ "booking_id": 12, "action": "check_in" \| "check_out" }` | `200 OK` | `400 Bad Request`, `403 Forbidden` | `{ "action": "check_in", "overtime_amount": 0.00 }` |
| `POST` | `/api/toggle-slot.php` | Attendant/Admin | `{ "slot_id": 4, "status": "available" \| "maintenance" }` | `200 OK` | `400 Bad Request`, `403 Forbidden` | `{ "message": "Slot updated" }` |
| `GET` | `/api/admin-stats.php` | Admin | None | `200 OK` | `403 Forbidden` | Revenue trends, occupancy matrix, vehicle distributions, peak arrival hours |

---

## 3. Role-Permission Matrix (RBAC)

| Resource / Action | Guest (Public) | User (Driver) | Attendant | Administrator |
|---|:---:|:---:|:---:|:---:|
| Search Facilities & View Map | ✅ | ✅ | ✅ | ✅ |
| View Slot Layout & Real-time Availability | ✅ | ✅ | ✅ | ✅ |
| Register Account / Sign In | ✅ | ✅ | ✅ | ✅ |
| Register & Manage Vehicles | ❌ | ✅ (Own Only) | ❌ | ✅ (All) |
| Book Slot & Pay Simulated Fee | ❌ | ✅ | ❌ | ✅ |
| View QR Pass & Download Ticket | ❌ | ✅ (Own Only) | ✅ (All) | ✅ (All) |
| Cancel Booking & Request Refund | ❌ | ✅ (Own Only) | ❌ | ✅ (All) |
| Gate Camera Scanner & Plate Lookup | ❌ | ❌ | ✅ | ✅ |
| Authorize Vehicle Entry (Check-In) | ❌ | ❌ | ✅ | ✅ |
| Process Vehicle Exit & Surcharges (Check-Out) | ❌ | ❌ | ✅ | ✅ |
| Live Facility Occupancy Matrix | ❌ | ❌ | ✅ | ✅ |
| Slot Maintenance Lock / Override | ❌ | ❌ | ✅ | ✅ |
| Facilities & Tariffs CRUD | ❌ | ❌ | ❌ | ✅ |
| Staff Management & Shift Assignments | ❌ | ❌ | ❌ | ✅ |
| Executive Analytics Dashboard & CSV Export | ❌ | ❌ | ❌ | ✅ |
| Immutable Security Audit Logs | ❌ | ❌ | ❌ | ✅ |

---

## 4. Database Relationships (InnoDB ERD)

```
[users] (id, name, email, password_hash, role, status)
   │
   ├── 1:N ──> [vehicles] (id, user_id, plate_number, vehicle_type, is_ev)
   │
   ├── 1:N ──> [attendant_assignments] (id, attendant_id, facility_id, shift_name)
   │                  │
   │                  └── M:1 ──> [facilities] (id, name, code, latitude, longitude, hourly_rate, ...)
   │                                     │
   │                                     └── 1:N ──> [slots] (id, facility_id, floor_level, zone_name, slot_number, status)
   │                                                       │
   ├── 1:N ──> [reservations] <────────────────────────────┘
   │                  │  (id, booking_reference, user_id, facility_id, slot_id, vehicle_plate, 
   │                  │   start_time, end_time, status, qr_token_hash, check_in_time, check_out_time)
   │                  │
   │                  └── 1:N ──> [payments] (id, reservation_id, user_id, transaction_ref, amount, status)
   │
   └── 1:N ──> [audit_logs] (id, user_id, action, entity_type, entity_id, ip_address, created_at)
```

---

## 5. Reservation State Machine

- `confirmed` → `checked_in`: Requires attendant authentication; checks that current time is within acceptable arrival window; updates slot status to `occupied`.
- `checked_in` → `completed`: Calculates elapsed time; compares against scheduled duration + facility grace period (`grace_period_mins`); assesses overtime fee if exceeded; updates slot status to `available`.
- `confirmed` → `cancelled`: Allowed only while in `confirmed` state; releases reserved slot; writes simulated refund payment record.
- `confirmed` → `expired`: Scheduled session expired without arrival; slot released for future reservations.

---

## 6. Threat Checklist & Security Controls

| Threat Category | Potential Risk | ParkPulse Security Mitigation |
|---|---|---|
| **SQL Injection (SQLi)** | Database extraction or authentication bypass | 100% prepared statements with parameter binding; `PDO::ATTR_EMULATE_PREPARES => false`. |
| **Cross-Site Scripting (XSS)** | Injected malicious client scripts | Strict `htmlspecialchars(..., ENT_QUOTES \| ENT_SUBSTITUTE, 'UTF-8')` on all outputs. |
| **Cross-Site Request Forgery (CSRF)** | Unauthorized state modification via third-party form | Cryptographic synchronizer tokens validated with timing-safe `hash_equals()`. |
| **Double Booking Race Conditions** | Simultaneous reservation of the same slot | Atomic transactions with `SELECT ... FOR UPDATE` row locks checking overlapping time intervals. |
| **QR Code Forgery / Guessing** | Unauthorized gate access via fabricated passes | Unguessable 48-hex-char cryptographically secure random tokens hashed via server HMAC-SHA256. |
| **Brute Force & Credential Stuffing** | Automated dictionary attacks against login | IP-based rate limiting table throttling attempts after 5 failures in a 5-minute window. |
| **Session Hijacking & Fixation** | Stolen session cookies | `session_regenerate_id(true)` on login, cookies configured with `HttpOnly`, `SameSite=Lax`, and `Secure` over HTTPS. |
| **Insecure Direct Object Reference (IDOR)** | User accessing other drivers' passes/vehicles | Server-side user ID matching on every query (`WHERE id = ? AND user_id = ?`). |
| **Secrets Leakage** | Exposed DB credentials or API keys | `.env` ignored in git; `.env.example` provides placeholders only; `.htaccess` denies web requests to sensitive files. |
| **Privileged Abuse** | Untracked attendant or admin overrides | Comprehensive immutable audit trail capturing actor user ID, action tag, client IP, and user agent. |
