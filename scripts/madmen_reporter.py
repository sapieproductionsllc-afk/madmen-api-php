"""
Reporter distribué MADMEN — boucle de "garde" tournant sur un PC du bureau.

Chaque tour :
  1) demande le tour de garde au cloud (POST /api/relay/claim, jeton GATEWAY_TOKEN) ;
  2) si ACCORDÉ : lit les pointages du K40 (lecture seule, ne désactive/vide rien) ;
  3) les pousse au récepteur PUSH existant du cloud (/iclock, HTTPS) — qui dédoublonne
     déjà via client_uuid et journalise dans k40_punch_brut (zéro perte).
Si NON accordé (un autre PC est de garde) ou si le K40 est injoignable : on ne fait
rien et on réessaie au tour suivant. Pensé pour être lancé en arrière-plan par l'app
MADMEN User (un process par PC). Une seule garde active à la fois, bascule auto < 1 min.

Config par variables d'environnement (défauts entre parenthèses) :
  CLOUD_URL (https://api-madmen.ssmanager.uk)  GATEWAY_TOKEN (requis)
  K40_IP (192.168.1.201)  K40_PORT (4370)  K40_PASSWORD (0)  K40_TIMEOUT (10)
  K40_SN (AKK0122578806)  HOLDER (hostname)  INTERVAL (45)  ONESHOT/--once
"""
import os
import sys
import json
import time
import socket
import urllib.request


def _post(url, data, headers, timeout):
    req = urllib.request.Request(url, data=data, method="POST", headers=headers)
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.status, r.read().decode("utf-8", "replace")


def claim(cfg):
    body = json.dumps({"holder": cfg["holder"]}).encode()
    st, txt = _post(cfg["cloud"] + "/api/relay/claim", body,
                    {"Authorization": "Bearer " + cfg["token"],
                     "Content-Type": "application/json"}, 15)
    return json.loads(txt)


def read_k40(cfg):
    from zk import ZK
    zk = ZK(cfg["ip"], port=cfg["port"], timeout=cfg["timeout"],
            password=cfg["password"], force_udp=True, ommit_ping=True)
    conn = zk.connect()
    try:
        att = conn.get_attendance() or []   # non destructif
        return [{"id": str(a.user_id),
                 "ts": a.timestamp.strftime("%Y-%m-%d %H:%M:%S")} for a in att]
    finally:
        try:
            conn.disconnect()
        except Exception:
            pass


def push(cfg, punches):
    # Format ATTLOG (tab-séparé) attendu par /iclock : userid \t ts \t status \t verify \t
    lines = "".join("%s\t%s\t0\t1\t\n" % (p["id"], p["ts"]) for p in punches).encode()
    url = cfg["cloud"] + "/iclock/cdata?SN=" + cfg["sn"] + "&table=ATTLOG"
    st, txt = _post(url, lines, {"Content-Type": "application/octet-stream"}, 25)
    return st, txt.strip()


def pending_fingerprints(cfg):
    """Tire la liste des gabarits d'empreinte EN ATTENTE de poussée K40 (sens cloud->K40)."""
    req = urllib.request.Request(cfg["cloud"] + "/api/relay/pending-fingerprints",
                                 method="GET",
                                 headers={"Authorization": "Bearer " + cfg["token"]})
    with urllib.request.urlopen(req, timeout=20) as r:
        return json.loads(r.read().decode("utf-8", "replace"))


def report_synced(cfg, bio_ids):
    """Confirme au cloud que ces gabarits sont posés sur le K40 (ne plus les re-pousser)."""
    body = json.dumps({"bio_ids": bio_ids}).encode()
    _post(cfg["cloud"] + "/api/relay/fingerprints-synced", body,
          {"Authorization": "Bearer " + cfg["token"],
           "Content-Type": "application/json"}, 15)


def push_templates(cfg, users):
    """Écrit des gabarits sur le K40 : crée l'utilisateur s'il manque, pousse le gabarit,
    puis refresh_data (sinon stocké mais jamais matché). Désactive/réactive le device."""
    import base64
    from zk import ZK
    from zk.finger import Finger
    zk = ZK(cfg["ip"], port=cfg["port"], timeout=cfg["timeout"],
            password=cfg["password"], force_udp=True, ommit_ping=True)
    conn = zk.connect()
    try:
        try:
            conn.disable_device()
        except Exception:
            pass
        existing = {}
        try:
            for u in (conn.get_users() or []):
                existing[str(u.user_id)] = u
        except Exception:
            pass
        for entry in users:
            uid = int(entry["uid"])
            user_id = str(entry.get("user_id") or uid)
            name = (entry.get("name") or user_id)[:24]
            fingers = []
            for f in entry.get("fingers", []):
                tmpl = base64.b64decode(f.get("template_b64") or "")
                if len(tmpl) >= 100:
                    fingers.append(Finger(uid=uid, fid=int(f["fid"]), valid=1, template=tmpl))
            if not fingers:
                continue
            target = existing.get(user_id)
            if target is None:
                conn.set_user(uid=uid, name=name, user_id=user_id)
                target = uid
            conn.save_user_template(target, fingers)
        try:
            conn.refresh_data()   # OBLIGATOIRE : sinon le gabarit est stocké mais non matché
        except Exception:
            pass
    finally:
        try:
            conn.enable_device()
        except Exception:
            pass
        try:
            conn.disconnect()
        except Exception:
            pass


def sync_fingerprints(cfg):
    """Pont DESCENDANT cloud->K40 : tire les empreintes en attente, les écrit sur le K40,
    puis confirme. Le cloud ne joint PAS le K40 ; ce PC de garde fait le pont (comme pour
    les pointages, mais en sens inverse)."""
    try:
        pend = pending_fingerprints(cfg)
    except Exception as e:
        return {"fp": "pull", "ok": False, "error": str(e)}
    users = pend.get("users") or []
    bio_ids = pend.get("bio_ids") or []
    if not users:
        return {"fp": "idle", "count": 0}
    try:
        push_templates(cfg, users)
    except Exception as e:
        return {"fp": "push", "ok": False, "error": str(e), "count": len(bio_ids)}
    try:
        report_synced(cfg, bio_ids)
    except Exception as e:
        return {"fp": "report", "ok": False, "error": str(e), "count": len(bio_ids)}
    return {"fp": "synced", "count": len(bio_ids)}


def one_cycle(cfg):
    try:
        rep = claim(cfg)
    except Exception as e:
        return {"step": "claim", "ok": False, "error": str(e)}
    if not rep.get("granted"):
        return {"step": "standby", "granted": False, "holder": rep.get("holder")}

    out = {"step": "push", "granted": True}
    # 1) Pointages K40 -> cloud (sens MONTANT, existant).
    try:
        punches = read_k40(cfg)
        if punches:
            st, resp = push(cfg, punches)
            out.update({"count": len(punches), "http": st, "resp": resp})
        else:
            out["count"] = 0
    except Exception as e:
        out.update({"ok": False, "error": str(e)})

    # 2) Empreintes cloud -> K40 (sens DESCENDANT, pont bidirectionnel).
    out["fingerprints"] = sync_fingerprints(cfg)
    return out


def _app_dir():
    base = os.environ.get("LOCALAPPDATA") or os.environ.get("APPDATA") or os.path.expanduser("~")
    d = os.path.join(base, "MadMen")
    try:
        os.makedirs(d, exist_ok=True)
    except OSError:
        pass
    return d


def _load_config_file():
    """Config de repli (KEY=VALUE) depuis %LOCALAPPDATA%\\MadMen\\reporter.env — permet de
    tourner via une TÂCHE PLANIFIÉE Windows sans variables d'env passées par l'app."""
    vals = {}
    path = os.environ.get("REPORTER_CONFIG") or os.path.join(_app_dir(), "reporter.env")
    try:
        with open(path, "r", encoding="utf-8") as f:
            for line in f:
                s = line.strip()
                if s and not s.startswith("#") and "=" in s:
                    k, v = s.split("=", 1)
                    vals[k.strip()] = v.strip()
    except OSError:
        pass
    return vals


def _log(msg):
    """Écrit le DERNIER état dans reporter.log (visibilité quand lancé sans console)."""
    try:
        with open(os.path.join(_app_dir(), "reporter.log"), "w", encoding="utf-8") as f:
            f.write(msg + "\n")
    except OSError:
        pass
    try:
        if sys.stderr:
            sys.stderr.write(msg + "\n")
            sys.stderr.flush()
    except Exception:
        pass


def _build_cfg():
    fv = _load_config_file()

    def g(key, default):
        v = os.environ.get(key) or fv.get(key)  # env d'abord, sinon fichier, sinon défaut
        return v if v else default

    return {
        "cloud": g("CLOUD_URL", "https://api-madmen.ssmanager.uk").rstrip("/"),
        "token": g("GATEWAY_TOKEN", ""),
        "ip": g("K40_IP", "192.168.1.201"),
        "port": int(g("K40_PORT", "4370")),
        "password": int(g("K40_PASSWORD", "0")),
        "timeout": int(g("K40_TIMEOUT", "10")),
        "sn": g("K40_SN", "AKK0122578806"),
        "holder": g("HOLDER", socket.gethostname()),
        "interval": int(g("INTERVAL", "45")),
    }


def main():
    if "--once" in sys.argv or os.environ.get("ONESHOT", "") == "1":
        sys.stdout.write(json.dumps(one_cycle(_build_cfg())))
        return 0
    while True:
        # On RELIT la config à CHAQUE tour : si l'app écrit reporter.env APRÈS le démarrage
        # de la tâche (course au boot/à l'install), le jeton est ramassé dès le tour suivant
        # — aucun redémarrage nécessaire. Le reporter devient insensible à l'ordre de boot.
        cfg = _build_cfg()
        r = one_cycle(cfg)
        _log(json.dumps(r))
        time.sleep(cfg["interval"])


if __name__ == "__main__":
    sys.exit(main())
