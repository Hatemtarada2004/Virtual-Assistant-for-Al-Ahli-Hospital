#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
واجهة API لتصنيف الأعراض إلى أقسام طبية
يعمل على المنفذ 5001
"""

import os, pickle, json
from pathlib import Path
from flask import Flask, request, jsonify

BASE_DIR  = Path(__file__).parent
MODELS_DIR = BASE_DIR / "models"

app = Flask(__name__)

# تحميل النموذج عند بدء التشغيل
pipeline, label_encoder, dept_info = None, None, {}

def load_models():
    global pipeline, label_encoder, dept_info
    try:
        with open(MODELS_DIR / "symptom_pipeline.pkl", "rb") as f:
            pipeline = pickle.load(f)
        with open(MODELS_DIR / "label_encoder.pkl", "rb") as f:
            label_encoder = pickle.load(f)
        with open(MODELS_DIR / "departments_info.json", "r", encoding="utf-8") as f:
            dept_info = json.load(f)
        print("✓ تم تحميل النموذج بنجاح")
        return True
    except FileNotFoundError:
        print("✗ ملفات النموذج غير موجودة — شغّل generate_and_train.py أولاً")
        return False


@app.route("/health", methods=["GET"])
def health():
    return jsonify({
        "status": "ok" if pipeline else "model_not_loaded",
        "model_loaded": pipeline is not None,
    })


@app.route("/api/symptom-check", methods=["POST"])
def symptom_check():
    if pipeline is None:
        return jsonify({"success": False, "message": "النموذج غير محمّل"}), 503

    data = request.get_json(force=True, silent=True) or {}
    text = str(data.get("text", "")).strip()

    if not text:
        return jsonify({"success": False, "message": "أرسل النص في حقل 'text'"}), 400

    # تحليل الأعراض
    proba = pipeline.predict_proba([text])[0]
    classes = label_encoder.classes_

    # أعلى 3 أقسام
    top_indices = proba.argsort()[-3:][::-1]
    top3 = []
    for i in top_indices:
        key = classes[i]
        info = dept_info.get(key, {})
        top3.append({
            "dept_id":   info.get("id"),
            "dept_key":  key,
            "dept_name": info.get("name", key),
            "confidence": round(float(proba[i]) * 100, 1),
        })

    best = top3[0]
    confidence = best["confidence"]
    dept_key = best["dept_key"]

    # تغيير موعد — إجابة خاصة
    if dept_key == "تغيير_موعد":
        note = "يبدو أنك تريد تغيير أو إلغاء موعد. بإمكانك إخباري برقم الموعد أو اسمك وسأساعدك."
    elif confidence >= 70:
        note = f"أعراضك تشير بشكل واضح إلى {best['dept_name']}."
    elif confidence >= 40:
        note = f"أعراضك تحيلنا إلى {best['dept_name']}، لكن ننصح بمراجعة الطبيب للتأكد."
    else:
        note = f"أعراضك قد تكون متعلقة بـ {best['dept_name']}، لكن يُفضّل الكشف الشامل."

    return jsonify({
        "success":     True,
        "dept_id":     best["dept_id"],
        "dept_key":    best["dept_key"],
        "dept_name":   best["dept_name"],
        "confidence":  confidence,
        "note":        note,
        "top3":        top3,
    })


@app.route("/api/symptom-check", methods=["OPTIONS"])
def symptom_check_options():
    resp = app.make_default_options_response()
    resp.headers["Access-Control-Allow-Origin"]  = "*"
    resp.headers["Access-Control-Allow-Headers"] = "Content-Type"
    resp.headers["Access-Control-Allow-Methods"] = "POST, OPTIONS"
    return resp


@app.after_request
def add_cors(response):
    response.headers["Access-Control-Allow-Origin"]  = "*"
    response.headers["Access-Control-Allow-Headers"] = "Content-Type"
    return response


if __name__ == "__main__":
    if load_models():
        print("🚀 Symptom API تعمل على http://localhost:5001")
        app.run(host="0.0.0.0", port=5001, debug=False)
    else:
        print("✗ تعذّر تشغيل الـ API — شغّل التدريب أولاً")
