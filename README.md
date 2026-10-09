# ParkPulse 2026 — Smart Parking Reservation & Management System
### *India / Gujarat Localization & Intelligent Multi-Category Parking Platform*

---

## 🌟 Overview

**ParkPulse 2026** is a modern, enterprise-grade Smart Parking Reservation and Management System engineered specifically for India, featuring a **Gujarat-first default experience** while fully supporting parking facilities, geocoding searches, and state selections across the entire nation.

Built with **PHP 8.x, MySQL / MariaDB (InnoDB with ACID transactions), Vanilla CSS, JavaScript Fetch API, Bootstrap 5, Leaflet 1.9.4 / OpenStreetMap, and Chart.js**, ParkPulse delivers an end-to-end parking reservation lifecycle — from real geocoded facility discovery and vehicle-slot compatibility validation to secure digital QR boarding passes, attendant gate barrier scanning, dynamic tariff billing, and real-time administrative analytics.

---

## 🚀 Key Features & India/Gujarat Localization

### 1. 🇮🇳 India-First & Gujarat-First Experience
- **Timezone & Locale**: Operates in `Asia/Kolkata` (IST, UTC+5:30) with Indian standard date-time conventions.
- **₹ INR Currency Handling**: All pricing, deposits, overstay surcharges, and revenue analytics are computed with integer paise precision (`paise = round(inr * 100)`) to eliminate floating-point calculation errors.
- **Gujarat Default & Quick City Filter Chips**: 10 primary Gujarat hubs with one-click filtering:
  - *Rajkot, Ahmedabad, Surat, Vadodara, Gandhinagar, Jamnagar, Bhavnagar, Junagadh, Morbi, Anand*.
- **Comprehensive Indian States & UTs**: Full selector supporting all **28 States** and **8 Union Territories**:
  - *States*: Andhra Pradesh, Arunachal Pradesh, Assam, Bihar, Chhattisgarh, Goa, Gujarat, Haryana, Himachal Pradesh, Jharkhand, Karnataka, Kerala, Madhya Pradesh, Maharashtra, Manipur, Meghalaya, Mizoram, Nagaland, Odisha, Punjab, Rajasthan, Sikkim, Tamil Nadu, Telangana, Tripura, Uttar Pradesh, Uttarakhand, West Bengal.
  - *Union Territories*: Andaman and Nicobar Islands, Chandigarh, Dadra and Nagar Haveli and Daman and Diu, Delhi (NCT), Jammu and Kashmir, Ladakh, Lakshadweep, Puducherry.
- **Indian Mobile Phone Validation**: Automatically normalizes leading `+91`, country codes, dashes, and spaces. Validates 10-digit mobile numbers with leading digits 6–9 and formats consistently as `+91 XXXXX XXXXX`.
- **Indian Address & Postal Standards**: Validates 6-digit PIN codes (`^[1-9][0-9]{5}$`), locality, district, state/UT, and real decimal GPS coordinates (`latitude`, `longitude`).
- **Fictional Gujarati Demo Personas**: Seeded accounts use realistic Gujarati names (*Dev Patel, Hetal Shah, Krunal Solanki, Nisha Parmar, Dhruv Joshi, Pooja Thakkar, Mehul Trivedi, Aarti Mehta, Jignesh Patel*).

---

### 2. 🚗 8 Vehicle Categories & Separate EV Charging Architecture
The platform strictly decouples the physical vehicle category from the electric powertrain (`is_ev`) flag:
1. **Car** (Sedan, Hatchback, SUV)
2. **Two-Wheeler / Bike** (Motorcycle, Scooter)
3. **Bicycle / Cycle**
4. **Auto-Rickshaw** (3-Wheeler)
5. **Van**
6. **Truck**
7. **Bus**
8. **Other** (Configurable Commercial)

#### Compatibility & Tariff Rules:
- **Server-Side Slot Dimension Constraints**: Large vehicles (Trucks, Buses, Vans) cannot book standard Car/Bike bays. Cars cannot reserve motorcycle stalls.
- **Independent EV Search Filter**: Searching "Car + EV Charging" filters for car-compatible parking bays equipped with active EV charging connectors (e.g. CCS2, Type 2 AC, Bharat AC-001) rather than assuming all EV bays fit all vehicles.
- **Category-Specific Hourly Tariffs**: Granular pricing models with baseline hourly rates, EV charging surcharges, and grace period overstay penalties.

---

### 3. 🗺️ Real Interactive Map & Geocoded Place Discovery
- **Leaflet 1.9.4 + OpenStreetMap**: Zero static screenshots or fake coordinates. Uses live OSM vector tiles with visible attribution: `© OpenStreetMap contributors`.
- **Live Geocoding Place Search (Nominatim)**:
  - Searches localities, landmarks, and addresses across India.
  - Debounced at 500ms with client-side in-memory caching to comply with OSM usage guidelines.
  - Graceful fallback when external geocoding services are unreachable.
- **Clear Visual Distinction of Parking Sources**:
  - **Managed System Facility** (`✔ Managed Facility · Instant Booking`): Backed by real database slot records and live availability. Allows direct reservation.
  - **Open Map Listing** (`📍 External Map POI · Availability not provided`): External OpenStreetMap point-of-interest. Explicitly flags availability as unverified and disables direct reservation to prevent fraudulent bookings.
- **Safe Directions Integration**: One-click "Open in Google Maps" and "View on OpenStreetMap" generated strictly via validated coordinates (`https://www.google.com/maps/dir/?api=1&destination={lat},{lng}`) with `target="_blank" rel="noopener noreferrer"`.
- **Browser Geolocation ("Near Me")**: Requests HTML5 geolocation with graceful fallback when denied.

---

### 4. 🎨 UI/UX, Navbar & Scrolling Layout Architecture
- **Standardized Z-Index Token Hierarchy**:
  - `--z-normal: 1`, `--z-sticky-element: 10`, `--z-map-container: 20`, `--z-map-controls: 400`, `--z-dropdown: 1000`, `--z-navbar: 1030`, `--z-navbar-dropdown: 1035`, `--z-modal-backdrop: 1050`, `--z-modal: 1055`, `--z-popover: 1060`, `--z-toast: 1090`.
- **Zero Navbar Bleed**: Leaflet zoom controls, cards, modals, and dropdowns remain properly layered below the sticky navbar during scrolling.
- **Fully Responsive**: Verified across Desktop (1440px), Tablet (1024px), and Mobile (390px).

---

### 5. 🛡️ Security & Enterprise Architecture
- **Strict Role-Based Access Control (RBAC)**: Admin, Attendant, and Driver/User roles with server-side middleware enforcement.
- **Password Security**: BCRYPT hashing with cost factor 12 (`password_hash` / `password_verify`).
- **Session Protection**: `session.use_strict_mode = 1`, `session.use_only_cookies = 1`, `session.cookie_httponly = 1`, SameSite cookies, session regeneration on login, and server-side invalidation on logout.
- **IDOR & Ownership Controls**: Strict object-level user ID checks for vehicles, bookings, digital passes, and payment receipts.
- **CSRF Defense**: Cryptographically secure per-session CSRF token validation on all state-changing `POST` requests.
- **Brute-Force Rate Limiting**: Client IP and action-based rate limiting with automatic throttling on authentication endpoints.
- **Immutable Audit Logging**: Every administrative modification, gate check-in, check-out, and financial adjustment is timestamped with client IP in `audit_logs`.
- **Double-Booking Prevention**: InnoDB row-level locking (`SELECT ... FOR UPDATE`) within database transactions guarantees concurrent requests cannot book overlapping time slots.

---

## 🛠️ Installation & Environment Setup

### System Prerequisites
- **Web Server**: Apache (e.g. XAMPP) with `mod_rewrite` enabled
- **PHP**: PHP 8.0 or higher (with `pdo`, `pdo_mysql`, `curl`, `mbstring`, `gd`)
- **Database**: MySQL 5.7+ or MariaDB 10.3+ with InnoDB support

### 1. Clone or Extract to Web Root
Place the project in your web directory (e.g., `C:\xampp\htdocs\smart parking` or `/var/www/html/smart-parking`).

### 2. Configure Environment Variables
Copy `.env.example` to `.env` and configure your database credentials:
```env
APP_NAME=ParkPulse 2026
APP_ENV=local
APP_URL=http://localhost/smart%20parking
APP_TIMEZONE=Asia/Kolkata

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=smart_parking_db
DB_USER=root
DB_PASS=
```

### 3. Run Database Migrations & Seed Data
Execute the safe migration script followed by the India/Gujarat data seeder:
```bash
# Run migration
php database/migrate_india_update.php

# Seed database with 15 facilities, 372 slots, multi-category vehicles, and Gujarati personas
php database/seed.php
```

### 4. Execute Automated Test Suites
Run the master test runner to verify 100% test coverage:
```bash
php tests/run_all_tests.php
```

---

## 👥 Seeded Demo Accounts (Fictional Personas)

All demo accounts use password: `User@2026` (or `Admin@2026` / `Attendant@2026` respectively):

| Role | Name | Email | Password | Details |
| :--- | :--- | :--- | :--- | :--- |
| **Administrator** | Super Admin | `admin@parkpulse.com` | `Admin@2026` | Full platform management, pricing, facilities, audit logs |
| **Attendant** | Krunal Solanki | `attendant.rajkot@parkpulse.com` | `Attendant@2026` | Rajkot Smart Multi-Level Hub gate scanner & occupancy |
| **Attendant** | Hetal Shah | `attendant.ahmedabad@parkpulse.com` | `Attendant@2026` | Ahmedabad Riverfront Parking terminal |
| **User (Driver)** | Dev Patel | `dev.patel@example.com` | `User@2026` | Registered vehicles: Car (Tata Nexon EV), Bike (Ather 450X) |
| **User (Driver)** | Nisha Parmar | `nisha.parmar@example.com` | `User@2026` | Registered vehicle: Bike (Honda Activa 6G) |
| **User (Driver)** | Dhruv Joshi | `dhruv.joshi@example.com` | `User@2026` | Registered vehicle: Auto-Rickshaw (Bajaj Compact 4S) |
| **User (Driver)** | Pooja Thakkar | `pooja.thakkar@example.com` | `User@2026` | Registered vehicle: Van (Maruti Eeco Cargo) |
| **User (Driver)** | Mehul Trivedi | `mehul.trivedi@example.com` | `User@2026` | Registered vehicle: Truck (Tata 407 Gold) |
| **User (Driver)** | Aarti Mehta | `aarti.mehta@example.com` | `User@2026` | Registered vehicle: Bus (Ashok Leyland 36-Seater) |
| **User (Driver)** | Jignesh Patel | `jignesh.patel@example.com` | `User@2026` | Registered vehicle: Bicycle (Hero Sprint Pro Hero) |

---

## 🧪 Automated Test Suite Scorecard

```
======================================================================
  PARKPULSE 2026 - MASTER AUTOMATED TEST RUNNER (CODE2WEB 2026)
======================================================================
▶ Running Suite: System & Domain OOP...                ✔ [SUCCESS] (70 Tests)
▶ Running Suite: HTTP & Session Integration...         ✔ [SUCCESS] (26 Tests)
▶ Running Suite: Security & RBAC...                    ✔ [SUCCESS] (56 Tests)
▶ Running Suite: Reservation, Pricing & Payment...     ✔ [SUCCESS] (39 Tests)
▶ Running Suite: QR Pass & Gate Barrier...             ✔ [SUCCESS] (41 Tests)
▶ Running Suite: Admin, Attendant & Analytics...       ✔ [SUCCESS] (27 Tests)
▶ Running Suite: Comprehensive Security Audit...       ✔ [SUCCESS] (15 Tests)
▶ Running Suite: Final Judge Flow (E2E)...             ✔ [SUCCESS] (23 Tests)
======================================================================
MASTER SCORECARD: 8 / 8 TEST SUITES PASSED (100% GREEN - 297/297 CHECKS)
======================================================================
```

---

## 📄 License & Attribution
- Map Tiles and Geocoding: [OpenStreetMap](https://www.openstreetmap.org/) © OpenStreetMap contributors under ODbL.
- Icons: [Bootstrap Icons](https://icons.getbootstrap.com/) under MIT License.
- Framework: Bootstrap 5.3 & Leaflet 1.9.4.
