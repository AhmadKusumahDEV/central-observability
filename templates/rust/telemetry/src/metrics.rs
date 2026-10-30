use std::time::{SystemTime, UNIX_EPOCH};

use opentelemetry::metrics::{Meter, ObservableGauge};

pub const HEARTBEAT_NAME: &str = "app.heartbeat.timestamp";

/// Gauge `app.heartbeat.timestamp` berisi waktu Unix saat ini. Alert service
/// down membandingkan nilai ini dengan waktu sekarang; nilainya bergerak
/// selama proses hidup tanpa perlu task latar belakang.
pub struct Heartbeat {
    // Registrasi berhenti saat instrument di-drop, jadi nilainya disimpan.
    _gauge: ObservableGauge<f64>,
}

impl Heartbeat {
    pub fn register(meter: &Meter) -> Self {
        let gauge = meter
            .f64_observable_gauge(HEARTBEAT_NAME)
            .with_unit("s")
            .with_description("Unix timestamp heartbeat terakhir aplikasi")
            .with_callback(|observer| {
                let now = SystemTime::now()
                    .duration_since(UNIX_EPOCH)
                    .map(|duration| duration.as_secs_f64())
                    .unwrap_or_default();
                observer.observe(now, &[]);
            })
            .build();

        Self { _gauge: gauge }
    }
}
