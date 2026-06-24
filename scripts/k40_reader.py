"""
Lecteur K40 pour le REPORTER distribué.

Lit les pointages du terminal (LECTURE SEULE) et les écrit en JSON sur STDOUT.
- Ne DÉSACTIVE JAMAIS le terminal -> n'interrompt pas un pointage en cours.
- Ne VIDE JAMAIS le buffer (get_attendance non destructif) -> aucune perte si la
  poussée vers le cloud échoue ; on peut tout relire au prochain tour.
- Le dédoublonnage est fait CÔTÉ CLOUD (client_uuid) : relire le buffer entier à
  chaque tour est sans risque (les doublons sont ignorés).

Usage :  python k40_reader.py <ip> [port] [password] [timeout]
Sortie :  {"ok": true, "punches": [{"id":"5","timestamp":"2026-06-24 08:00:00","status":0}, ...], "count": N}
          {"ok": false, "error": "..."}   (+ code de sortie 1)
"""
import sys
import json

try:
    from zk import ZK
except Exception as e:  # pyzk absent
    sys.stdout.write(json.dumps({"ok": False, "error": "pyzk_import_failed: %s" % e}))
    sys.exit(1)


def main():
    ip = sys.argv[1] if len(sys.argv) > 1 else None
    port = int(sys.argv[2]) if len(sys.argv) > 2 else 4370
    password = int(sys.argv[3]) if len(sys.argv) > 3 else 0
    timeout = int(sys.argv[4]) if len(sys.argv) > 4 else 10
    if not ip:
        sys.stdout.write(json.dumps({"ok": False, "error": "missing_ip"}))
        return 1

    zk = ZK(ip, port=port, timeout=timeout, password=password,
            force_udp=True, ommit_ping=True)
    conn = None
    try:
        conn = zk.connect()
    except Exception as e:
        sys.stdout.write(json.dumps({"ok": False, "error": "connect_failed: %s" % e}))
        return 1
    try:
        att = conn.get_attendance() or []   # non destructif : ne vide pas le terminal
        punches = [
            {"id": str(a.user_id),
             "timestamp": a.timestamp.strftime("%Y-%m-%d %H:%M:%S"),
             "status": int(a.status)}
            for a in att
        ]
        sys.stdout.write(json.dumps({"ok": True, "punches": punches, "count": len(punches)}))
        return 0
    except Exception as e:
        sys.stdout.write(json.dumps({"ok": False, "error": "read_failed: %s" % e}))
        return 1
    finally:
        try:
            conn.disconnect()
        except Exception:
            pass


if __name__ == "__main__":
    sys.exit(main())
