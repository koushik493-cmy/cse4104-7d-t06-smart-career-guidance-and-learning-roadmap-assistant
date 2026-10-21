# 14_Deployment_Guide.md

# Aria: Smart Career Guidance & Learning Roadmap Assistant
## Production Cloud Deployment Specification & Guide

**Version:** 2.0 (Production Live)  
**Status:** Deployed & Operational  
**Last Updated:** October 2026  

---

## 1. Executive Summary & Live Endpoints

Aria is deployed using a decoupled, cloud-native micro-architecture leveraging industry-standard managed cloud providers on their zero-cost tiers. Each tier operates independently to ensure high availability, automatic scaling, and frictionless continuous integration/continuous deployment (CI/CD).

| Component | Provider / Platform | Tier | Live Production URL |
|---|---|---|---|
| **Frontend Application** | **Vercel** | Free Tier | [https://aria-smart-career.vercel.app](https://aria-smart-career.vercel.app) |
| **Backend API Service** | **Render** | Free Web Service | [https://aria-backend-7cuo.onrender.com](https://aria-backend-7cuo.onrender.com) |
| **Database Engine** | **Supabase** | Free PostgreSQL 17 | `aws-0-ap-northeast-1.pooler.supabase.com:5432` |
| **Health Check Endpoint**| **Render** | HTTP 200 OK | [https://aria-backend-7cuo.onrender.com/up](https://aria-backend-7cuo.onrender.com/up) |
| **AI Engine** | **Google Cloud** | Developer Tier | Google Gemini API (`gemini-3.8-flash`) |

---

## 2. System Architecture

```text
┌────────────────────────────────────────────────────────┐
│                   CLIENT BROWSER                       │
│      https://aria-smart-career.vercel.app              │
└──────────────────────────┬─────────────────────────────┘
                           │
                           │ HTTPS / JSON (REST API + Bearer Token)
                           ▼
┌────────────────────────────────────────────────────────┐
│                   VERCEL EDGE CDN                      │
│   • Global Static Edge Delivery                        │
│   • Client-side SPA Routing (vercel.json)              │
│   • React 19 + TypeScript + Vite + Tailwind CSS        │
└──────────────────────────┬─────────────────────────────┘
                           │
                           │ Axios REST Requests
                           ▼
┌────────────────────────────────────────────────────────┐
│                RENDER WEB SERVICE                      │
│   • Service: aria-backend (Docker Container)           │
│   • Runtime: PHP 8.4 + Apache (mpm_prefork)            │
│   • Framework: Laravel 12 API Backend                  │
│   • Reverse Proxy SSL Termination (X-Forwarded-*)      │
│   • Dynamic Port Binding ($PORT)                       │
└──────────────┬──────────────────────────┬──────────────┘
               │                          │
               │ IPv4 Pooler (Port 5432) │ HTTPS (TLS 1.3)
               │ SSL Mode: require        │
               ▼                          ▼
┌──────────────────────────────┐  ┌──────────────────────────────┐
│     SUPABASE POSTGRESQL      │  │      GOOGLE GEMINI API       │
│  • Engine: PostgreSQL 17     │  │  • Career Recommendations    │
│  • Host: aws-0-ap-northeast-1│  │  • Skill Gap Analysis        │
│  • Supavisor Session Pooler  │  │  • Dynamic Roadmap Generation│
│  • 34 Relational Tables      │  └──────────────────────────────┘
└──────────────────────────────┘
```

---

## 3. Frontend Deployment (Vercel)

### 3.1 Overview
The frontend is built using **React 19**, **TypeScript**, and **Vite**, styled with **Tailwind CSS**, and deployed onto Vercel's global Content Delivery Network (CDN).

### 3.2 Key Configuration Files

#### `frontend/vite.config.ts`
Configured to output production assets directly to `dist/`:
```typescript
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  build: {
    outDir: process.env.BUILD_TO_LARAVEL ? '../backend/public' : 'dist',
    emptyOutDir: process.env.BUILD_TO_LARAVEL ? false : true,
    chunkSizeWarningLimit: 1000,
  }
})
```

#### `frontend/vercel.json`
Ensures Single Page Application (SPA) client-side routing functions properly upon hard browser refreshes without returning HTTP 404:
```json
{
  "rewrites": [
    {
      "source": "/(.*)",
      "destination": "/index.html"
    }
  ]
}
```

### 3.3 Environment Variables
| Variable | Value | Description |
|---|---|---|
| `VITE_API_URL` | `https://aria-backend-7cuo.onrender.com/api` | Base URL for all backend REST API calls |

---

## 4. Backend Deployment (Render)

### 4.1 Overview
The backend is a **Laravel 12** REST API application packaged into an official **PHP 8.4 Apache** Docker container and deployed as a Web Service on Render.

### 4.2 Docker Architecture (`backend/Dockerfile`)
The backend uses a tailored container supporting PostgreSQL drivers, image manipulation, and PDF generation:

```dockerfile
FROM php:8.4-apache

ENV DEBIAN_FRONTEND=noninteractive
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update && apt-get install -y --no-install-recommends \
    git curl unzip zip libpq-dev libzip-dev \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo pdo_pgsql pgsql zip bcmath gd mbstring opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev

COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80
CMD ["/usr/local/bin/start.sh"]
```

### 4.3 Container Startup Script (`backend/docker/start.sh`)
Executes at runtime inside Render:
1. Re-binds Apache to Render's dynamically assigned `$PORT`.
2. Creates the `storage:link` symlink.
3. Caches configuration, routes, and views (`config:cache`, `route:cache`, `view:cache`).
4. Executes database migrations against Supabase (`php artisan migrate --force`).
5. Optionally seeds reference data (`php artisan db:seed --force` when `SEED_DATABASE=true`).
6. Starts the Apache web server in the foreground.

### 4.4 Reverse Proxy SSL Support (`backend/bootstrap/app.php`)
Render terminates SSL at its cloud load balancers and forwards requests to the container via HTTP. To ensure Laravel properly generates `https://` URLs and accepts API requests, proxy trust is configured:
```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: '*');
    $middleware->alias([
        'is_admin' => \App\Http\Middleware\IsAdmin::class,
    ]);
})
```

---

## 5. Database Deployment (Supabase PostgreSQL)

### 5.1 Architecture & IPv4 Resolution
Supabase provides a hosted **PostgreSQL 17** engine. Because cloud platforms like Render operate on IPv4 outbound networks, connection to Supabase uses the **Supavisor Connection Pooler** in Session Mode on port `5432`.

### 5.2 Connection Details
- **Host**: `aws-0-ap-northeast-1.pooler.supabase.com`
- **Port**: `5432`
- **Database**: `postgres`
- **Username**: `postgres.cqluxullmizndlkifvlf`
- **SSL Mode**: `require`

### 5.3 Database Schema (34 Relational Tables)
The database schema consists of 34 core tables initialized via Laravel migrations:
- **Authentication & Security**: `users`, `password_reset_tokens`, `sessions`, `personal_access_tokens`
- **Profiles & Academics**: `user_profiles`, `user_skills`, `skills`, `skill_gap_analyses`
- **Career Management**: `careers`, `career_categories`, `career_skills`, `career_resources`, `career_recommendations`
- **Roadmaps & Learning**: `learning_roadmaps`, `roadmap_steps`, `completed_steps`, `progress_logs`
- **Interview & AI Tracking**: `interview_sessions`, `interview_questions`, `ai_histories`, `user_api_keys`
- **System & Observability**: `activity_logs`, `notifications`, `cache`, `jobs`, `telescope_entries`

---

## 6. Environment Variables Reference

### Render Backend Environment Variables
```env
APP_NAME=aria
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:qNrVNo0ixvSfwAlOiXhZRl1tTr0REp4wN9N8UAFDkE4=
APP_URL=https://aria-backend-7cuo.onrender.com

LOG_CHANNEL=stderr
LOG_LEVEL=error

DB_CONNECTION=pgsql
DB_HOST=aws-0-ap-northeast-1.pooler.supabase.com
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres.cqluxullmizndlkifvlf
DB_PASSWORD=DatabaseStrongPassword$
DB_SSLMODE=require

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

SEED_DATABASE=false
PULSE_ENABLED=false
TELESCOPE_ENABLED=false

GEMINI_MODEL=gemini-3.8-flash
GEMINI_API_KEY=your_gemini_api_key_here
```

### Vercel Frontend Environment Variables
```env
VITE_API_URL=https://aria-backend-7cuo.onrender.com/api
```

---

## 7. Pre-Seeded Default Accounts

For testing, review, and demonstration, the database contains default pre-seeded credentials:

| Role | Email | Password | Permissions |
|---|---|---|---|
| **Admin** | `admin@aria.com` | `password` | Full Administrative & Resource Access |
| **Student** | `student@aria.com` | `password` | Student Dashboard, Roadmaps, Interviews |
| **Student 2** | `sara@aria.com` | `password` | Secondary User Profile |

---

## 8. Continuous Integration & Deployment (CI/CD)

The project is linked to the GitHub repository:  
`https://github.com/koushik493-cmy/cse4104-7d-t06-smart-career-guidance-and-learning-roadmap-assistant`

### Automated Workflow:
```text
Developer Git Push (Branch: main)
       │
       ├───────────────────────────────────────────┐
       ▼                                           ▼
Render Webhook Trigger                      Vercel Webhook Trigger
       │                                           │
Render Docker Build                         Vercel Vite Build
 • Pulls PHP 8.4 image                       • Runs `npm run build`
 • Installs Composer dependencies            • Emits static bundle to `dist/`
 • Caches routes & config                    • Deploys to Global Edge CDN
 • Runs database migrations                  • Updates Live DNS Alias
       │                                           │
       ▼                                           ▼
Backend Live (HTTP 200)                     Frontend Live (HTTP 200)
```

---

## 9. Operational Considerations & Gotchas

### 9.1 Render Free Tier Cold Starts
On the free tier, Render puts the web service container into sleep mode after **15 minutes of inactivity**.
- **Cold Start Behavior:** The first incoming HTTP request takes approximately **30 to 45 seconds** to spin up the container.
- **Warm Performance:** Subsequent requests execute with normal sub-second latencies.

### 9.2 Supabase IPv6 vs IPv4 Pooler
Supabase free tier's direct host (`db.[ref].supabase.co`) resolves to IPv6 only. Because Render free web services only possess outbound IPv4 networking, connecting directly fails with `ENOTFOUND` or `Network unreachable`. Always use the **Supavisor pooler domain** (`aws-0-[region].pooler.supabase.com:5432`) with `DB_SSLMODE=require`.

### 9.3 Database Seeding Guard
To prevent duplicate key errors or unwanted data overwrites on subsequent deployments, `SEED_DATABASE` is set to `false` in Render environment settings. It should only be set to `true` during a fresh database wipe.

---

## 10. Smoke Testing & Verification Checklist

- [x] **Frontend Home & Landing:** [https://aria-smart-career.vercel.app](https://aria-smart-career.vercel.app) loads with CSS and icons intact.
- [x] **SPA Routing:** Direct reload of subroutes (`/login`, `/dashboard`) returns HTTP 200 without 404 errors.
- [x] **Backend Health Check:** `GET /up` returns HTTP 200 OK.
- [x] **API Authentication:** `POST /api/login` issues a valid Sanctum Bearer token for seeded accounts.
- [x] **Database Connectivity:** Supabase dashboard reflects 34 initialized public tables.
- [x] **CORS Communication:** Cross-origin communication between Vercel and Render is successful.
