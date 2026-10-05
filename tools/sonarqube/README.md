# SonarQube

SonarQube **Community Edition 26.9.0.129388** — the version fixed by the assessment —
with the default **Sonar way** Quality Gate.

```bash
./run.sh sonar        # start the server, regenerate coverage, analyse, print the gate
./run.sh sonar-stop   # stop it (analysis history survives in the volumes)
```

The first run pulls ~2.6 GB and takes a few minutes. Later runs take about a minute.
The dashboard is at **http://localhost:9310/dashboard?id=stockpile**, sign in `admin` /
`StockpileSonar1!` (override with `SONAR_ADMIN_PASSWORD`). That password protects a local,
throwaway analysis server with no data of its own; it is not a credential for anything else.

## Why this is a separate stack

`compose.yaml` in the repository root is the file an assessor runs to start the application.
It contains the application and its database and nothing else. A code-quality server is a
development tool — the application never talks to it — and folding it in would make
`docker compose up` pull 2.6 GB and reserve 2 GB of RAM for something unrelated to the system
being graded.

## Three things that are easy to get wrong

**The project is mounted at `/var/www/html`, the same path the app container uses.**
`build/coverage/clover.xml` is generated inside the app container and records *absolute* paths.
Mount the project anywhere else for the scan and Sonar imports **zero coverage** — silently,
with no warning, reporting 0.0% as though the tests covered nothing.

**Port 9310, not the default 9000.** On macOS an IPv6-only listener on `::1:9000` wins the race
for `localhost` even while Docker holds `*:9000`, so a stray dev server quietly answers in
SonarQube's place. 9000 and 9001 were both already taken on the development machine.

**The healthcheck uses `curl`.** This image ships no `wget`; a healthcheck written against it
reports *unhealthy* forever while the server is fine.

## Coverage driver

`pcov`, installed in the main `Dockerfile` but **disabled by default** (`pcov.enabled=0`) and
switched on only by `composer test:coverage`. Xdebug would do the same job but is a debugger
first and slows the suite several times over; pcov does nothing but line coverage. The ordinary
`composer test` therefore pays nothing for it.

## Current state and its honest weak spot

0 bugs · 0 vulnerabilities · 0 security hotspots · duplication 1.7% · Reliability **A** ·
Security **A** · Maintainability **A** · Quality Gate **OK**.

Coverage is **30.8%**, and that number is not flattering. It is a direct consequence of the
architecture: unit tests target Services through `InMemory*Repository`, so `Controller` and
`MySql*Repository` are barely executed. Controllers could have been excluded from coverage to
produce a prettier figure; they were not, because that removes the evidence rather than the gap.
See `docs/quality/static-analysis.md` §6 for the full reasoning and
`docs/quality/tech-debt.md` TD-07.
