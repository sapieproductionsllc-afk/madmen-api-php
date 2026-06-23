"""
Pont K40 — upload/suppression de gabarits d'empreinte via pyzk.
Lit un JSON sur STDIN, écrit un JSON résultat sur STDOUT.

ENTRÉE (STDIN) :
{
  "ip": "192.168.1.201", "port": 4370, "password": 0, "timeout": 15,
  "action": "push" | "remove",
  "users": [
    { "uid": 12, "user_id": "12", "name": "Jean Dupont",
      "fingers": [ { "fid": 6, "template_b64": "..." }, ... ] }
  ]
}

SORTIE (STDOUT) :
{ "ok": true, "results": [ {"uid":12,"fid":6,"ok":true,"size":1024}, ... ],
  "synced": 3, "failed": 0 }

Codes de sortie : 0 = session OK (voir results par item), 1 = échec global
(connexion impossible, JSON invalide). Le gabarit n'est JAMAIS imprimé.
"""
import sys
import json
import base64

try:
    from zk import ZK
    from zk.finger import Finger
    from zk.user import User
except Exception as e:  # pyzk absent
    sys.stdout.write(json.dumps({"ok": False, "error": "pyzk_import_failed: %s" % e}))
    sys.exit(1)

MIN_TEMPLATE_BYTES = 100  # un vrai gabarit ZKFinger fait ~500-2000 o ; rejette les random_bytes(32)


def read_input():
    raw = sys.stdin.buffer.read()
    if not raw:
        raise ValueError("stdin vide")
    # utf-8-sig : tolère un éventuel BOM en tête (ex. pipe PowerShell).
    return json.loads(raw.decode("utf-8-sig"))


def find_user(conn, uid, user_id):
    """Retourne l'objet User existant sur le K40, ou None."""
    try:
        users = conn.get_users()
    except Exception:
        return None
    u = next((x for x in users if int(x.uid) == int(uid)), None)
    if u is None:
        u = next((x for x in users if str(x.user_id) == str(user_id)), None)
    return u


def ensure_user(conn, uid, user_id, name):
    """Garantit l'existence du User AVANT save_user_template (idempotent)."""
    u = find_user(conn, uid, user_id)
    if u is not None:
        return u
    conn.set_user(uid=int(uid), name=(name or str(user_id))[:24],
                  user_id=str(user_id))
    return find_user(conn, uid, user_id)


def do_push(conn, users):
    results = []
    for entry in users:
        uid = int(entry["uid"])
        user_id = str(entry.get("user_id") or uid)
        name = entry.get("name") or str(user_id)
        fingers = []
        item_errs = []
        for f in entry.get("fingers", []):
            fid = int(f["fid"])
            if not (0 <= fid <= 9):
                item_errs.append({"fid": fid, "ok": False, "error": "fid_out_of_range"})
                continue
            tmpl = base64.b64decode(f["template_b64"] or "")
            if len(tmpl) < MIN_TEMPLATE_BYTES:
                item_errs.append({"fid": fid, "ok": False, "error": "template_too_small",
                                  "size": len(tmpl)})
                continue
            fingers.append(Finger(uid=uid, fid=fid, valid=1, template=tmpl))

        if not fingers:
            results.append({"uid": uid, "ok": False, "error": "no_valid_finger",
                            "details": item_errs})
            continue

        try:
            user = ensure_user(conn, uid, user_id, name)
            target = user if user is not None else uid
            conn.save_user_template(target, fingers)
            # Vérification par doigt (pas get_templates global, trop lent)
            for fg in fingers:
                try:
                    chk = conn.get_user_template(uid=uid, temp_id=fg.fid)
                    ok = chk is not None and len(chk.template) >= MIN_TEMPLATE_BYTES
                    size = len(chk.template) if chk is not None else 0
                except Exception:
                    ok, size = False, 0
                results.append({"uid": uid, "fid": fg.fid, "ok": ok, "size": size})
            for err in item_errs:
                err["uid"] = uid
                results.append(err)
        except Exception as e:
            results.append({"uid": uid, "ok": False, "error": "save_failed: %s" % e})
    return results


def do_remove(conn, users):
    """Supprime des doigts précis (temp_id = fid). Si fingers vide -> remove user entier."""
    results = []
    for entry in users:
        uid = int(entry["uid"])
        user_id = str(entry.get("user_id") or uid)
        fingers = entry.get("fingers", [])
        try:
            if fingers:
                for f in fingers:
                    fid = int(f["fid"])
                    conn.delete_user_template(uid=uid, temp_id=fid, user_id=str(user_id))
                    results.append({"uid": uid, "fid": fid, "ok": True, "removed": True})
            else:
                conn.delete_user(uid=uid)
                results.append({"uid": uid, "ok": True, "removed_user": True})
        except Exception as e:
            results.append({"uid": uid, "ok": False, "error": "remove_failed: %s" % e})
    return results


def main():
    try:
        data = read_input()
    except Exception as e:
        sys.stdout.write(json.dumps({"ok": False, "error": "bad_input: %s" % e}))
        return 1

    ip = data.get("ip")
    port = int(data.get("port") or 4370)
    password = int(data.get("password") or 0)
    timeout = int(data.get("timeout") or 15)
    action = data.get("action", "push")
    users = data.get("users") or []

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
        try:
            conn.disable_device()
        except Exception:
            pass

        if action == "attendance":
            att = conn.get_attendance() or []
            out = {"ok": True, "attendance": [
                {"id": str(a.user_id),
                 "timestamp": a.timestamp.strftime("%Y-%m-%d %H:%M:%S"),
                 "status": int(a.status)}
                for a in att
            ]}
            sys.stdout.write(json.dumps(out))
            return 0

        if action == "remove":
            results = do_remove(conn, users)
        else:
            results = do_push(conn, users)

        # Recharge le terminal : les gabarits poussés entrent dans le moteur de
        # reconnaissance SANS redémarrage manuel du K40 (sinon ils restent "stockés
        # mais non matchables" jusqu'au prochain reboot).
        try:
            conn.refresh_data()
        except Exception:
            pass

        synced = sum(1 for r in results if r.get("ok"))
        failed = sum(1 for r in results if not r.get("ok"))
        out = {"ok": failed == 0, "results": results,
               "synced": synced, "failed": failed}
        sys.stdout.write(json.dumps(out))
        return 0
    finally:
        # Réactiver le terminal QUOI QU'IL ARRIVE (sinon pointage paralysé).
        try:
            conn.enable_device()
        except Exception:
            pass
        try:
            conn.disconnect()
        except Exception:
            pass


if __name__ == "__main__":
    sys.exit(main())
