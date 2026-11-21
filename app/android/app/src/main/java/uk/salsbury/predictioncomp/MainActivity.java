package uk.salsbury.predictioncomp;

import android.os.Bundle;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        // Keep text at the size the site was designed for (the phone font-size setting would otherwise scale it).
        getBridge().getWebView().getSettings().setTextZoom(100);
    }
}
