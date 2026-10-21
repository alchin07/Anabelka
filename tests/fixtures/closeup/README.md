# Exact close-up regression fixtures

- `20260904-111401-44eb8f1e1cbac540.jpg`: unchanged user-supplied original, 2500×3250; SHA-256 `c45e4d4444b26701005fda5dc3332e2a418936ae4a251d761a3130da59ce9d7b`. The original's upper-right RGB p90 on a Pillow BILINEAR 394×512 probe is 4.1231055.
- `44eb8f1e1cbac540-1182x1536.jpg`: the earlier reduced copy actually used in the previous investigation; SHA-256 `1bfd68d7dc5122a135ce0eede95ec3e93856eaacafcf9258ae40eee41d64957b`. Its corresponding p90 is 3.7749171. It is not reproduced merely by a fresh BILINEAR resize; the tests also check that independently generated resize.
- `44eb8f1e1cbac540-modnet-alpha.png`: unmodified 2500×3250 output captured from the real `modnet_worker.py` subprocess, before bridge cleanup; SHA-256 `e054515eb20064d995c8e8d5cd969521d4e1525ff77285b4437a902638a7349a`. Produced with the repository-pinned model SHA-256 `5069a5e306b9f5e9f4f2b0360264c9f8ea13b257c7c39943c7cf6a2ec3a102ae`, ONNX Runtime 1.31.0 CPU provider. Foreground ratio at alpha ≥128: 0.8631379692307692. Baseline and corrected runs produced identical PNG bytes.

The alpha fixture lets ordinary tests validate genuine inference output without requiring ONNX weights/runtime. It is not a hand-painted expected mask. The separate full-process verification runs the real worker, detectors and output writing on all five originals.
