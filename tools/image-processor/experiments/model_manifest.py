"""Pinned experimental artifacts; no caller-supplied checksum or model URL."""

BIREfNET_FILENAME = "BiRefNet-general-bb_swin_v1_tiny-epoch_232.onnx"
MODELS = {
    "birefnet": {
        "filename": BIREfNET_FILENAME,
        "bytes": 224005088,
        "sha256": "5600024376f572a557870a5eb0afb1e5961636bef4e1e22132025467d0f03333",
        "publisher_md5": "4fab47adc4ff364be1713e97b7e66334",
        "url": "https://github.com/ZhengPeng7/BiRefNet/releases/download/v1/" + BIREfNET_FILENAME,
        "profile": "rembg-birefnet-general-lite-rgb-globalmax-imagenet-lanczos-sigmoid-minmax-v1",
        "intra_op_threads": 1,
        "inter_op_threads": 1,
    },
    "modnet": {
        "filename": "modnet_photographic_portrait_matting.onnx",
        "bytes": 25969398,
        "sha256": "5069a5e306b9f5e9f4f2b0360264c9f8ea13b257c7c39943c7cf6a2ec3a102ae",
        "profile": "existing-modnet-worker-unchanged",
        "intra_op_threads": 2,
        "inter_op_threads": 1,
    },
}
